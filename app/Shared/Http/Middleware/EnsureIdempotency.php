<?php

namespace App\Shared\Http\Middleware;

use App\Shared\Enums\ErrorCode;
use App\Shared\Http\ApiResponse;
use App\Shared\Services\Fingerprint;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Idempotency-Key for critical writes (api-specification.md §1.4).
 *
 * - First request with a key: runs, and its response is stored for 48 hours.
 * - Same key, same request: the stored response is replayed (Idempotency-Replayed: true).
 * - Same key, different request: 422 IDEMPOTENCY_KEY_REUSED.
 * - Same key while the first is still running: 409 IDEMPOTENCY_IN_PROGRESS.
 * - A 5xx or an exception releases the key so the client can retry.
 *
 * Keys are scoped to the signed-in user (or the client IP), so one user can
 * never replay another user's response. Stored bodies are encrypted. Do not
 * use this on endpoints whose responses carry credentials (login, refresh).
 */
final class EnsureIdempotency
{
    public const HEADER = 'Idempotency-Key';

    private const HOURS = 48;

    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $next($request);
        }

        $key = (string) $request->headers->get(self::HEADER, '');

        if (! Str::isUuid($key) && ! Str::isUlid($key)) {
            return ApiResponse::error(ErrorCode::BadRequest, 'An Idempotency-Key header (UUID or ULID) is required for this request.', [
                'Idempotency-Key' => ['Send a new UUID or ULID for each distinct request, and the same one when retrying it.'],
            ]);
        }

        $user = $request->user();
        $scope = $user !== null ? 'user:'.$user->getAuthIdentifier() : 'ip:'.Fingerprint::ip($request->ip());
        $route = mb_substr($request->method().' '.($request->route()?->uri() ?? $request->path()), 0, 160);
        $hash = hash('sha256', $request->method().'|'.$request->path().'|'.json_encode($this->canonical($request->all())));

        DB::table('idempotency_keys')->where(compact('scope', 'route', 'key'))->where('expires_at', '<', now())->delete();

        $id = (string) Str::uuid7();

        try {
            // Savepoint: a duplicate key must not poison an enclosing transaction.
            DB::transaction(fn () => DB::table('idempotency_keys')->insert([
                'id' => $id, 'scope' => $scope, 'route' => $route, 'key' => $key, 'request_hash' => $hash,
                'status' => 'IN_PROGRESS', 'expires_at' => now()->addHours(self::HOURS), 'created_at' => now(), 'updated_at' => now(),
            ]));
        } catch (UniqueConstraintViolationException) {
            return $this->existing($scope, $route, $key, $hash);
        }

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            DB::table('idempotency_keys')->where('id', $id)->delete();

            throw $e;
        }

        if ($response->getStatusCode() >= 500) {
            DB::table('idempotency_keys')->where('id', $id)->delete();

            return $response;
        }

        DB::table('idempotency_keys')->where('id', $id)->update([
            'status' => 'COMPLETED',
            'response_status' => $response->getStatusCode(),
            'response_body_encrypted' => Crypt::encryptString((string) $response->getContent()),
            'updated_at' => now(),
        ]);

        return $response;
    }

    private function existing(string $scope, string $route, string $key, string $hash): Response
    {
        $row = DB::table('idempotency_keys')->where(compact('scope', 'route', 'key'))->first();

        if ($row === null) {
            return ApiResponse::error(ErrorCode::IdempotencyInProgress);
        }

        if (! hash_equals((string) $row->request_hash, $hash)) {
            return ApiResponse::error(ErrorCode::IdempotencyKeyReused);
        }

        if ($row->status !== 'COMPLETED') {
            return ApiResponse::error(ErrorCode::IdempotencyInProgress);
        }

        return new JsonResponse(
            json_decode(Crypt::decryptString((string) $row->response_body_encrypted), true),
            (int) $row->response_status,
            ['Idempotency-Replayed' => 'true'],
        );
    }

    /**
     * Key order must not change the hash: {"a":1,"b":2} and {"b":2,"a":1} are the same request.
     *
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    private function canonical(array $data): array
    {
        ksort($data);

        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $data[$k] = $this->canonical($v);
            }
        }

        return $data;
    }
}
