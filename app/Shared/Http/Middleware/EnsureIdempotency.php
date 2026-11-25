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
 * - A 5xx, an exception, or a 409 CONCURRENT_MODIFICATION releases the key so the client can retry.
 * - A key left IN_PROGRESS by a crashed request is taken over by a retry after
 *   ABANDONED_AFTER_MINUTES, instead of blocking the key for 48 hours.
 *
 * Keys are scoped to the signed-in user (or the client IP), so one user can
 * never replay another user's response. Stored bodies are encrypted. Do not
 * use this on endpoints whose responses carry credentials (login, refresh).
 */
final class EnsureIdempotency
{
    public const HEADER = 'Idempotency-Key';

    private const HOURS = 48;

    public const ABANDONED_AFTER_MINUTES = 5;

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
            $existing = $this->existing($scope, $route, $key, $hash);

            if ($existing instanceof Response) {
                return $existing;
            }

            $id = $existing; // abandoned key taken over: run the request now
        }

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            DB::table('idempotency_keys')->where('id', $id)->delete();

            throw $e;
        }

        // A 5xx, or a 409 that only says "another change is in progress, retry" (CONCURRENT_MODIFICATION), is not an answer to replay for 48 hours.
        if ($response->getStatusCode() >= 500 || $this->isRetryable($response)) {
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

    private function isRetryable(Response $response): bool
    {
        if ($response->getStatusCode() !== 409) {
            return false;
        }
        $body = json_decode((string) $response->getContent(), true);

        return is_array($body) && ($body['code'] ?? null) === 'CONCURRENT_MODIFICATION';
    }

    /** @return Response|string a response to return, or the id of an abandoned key now owned by this request */
    private function existing(string $scope, string $route, string $key, string $hash): Response|string
    {
        $row = DB::table('idempotency_keys')->where(compact('scope', 'route', 'key'))->first();

        if ($row === null) {
            return ApiResponse::error(ErrorCode::IdempotencyInProgress);
        }

        if (! hash_equals((string) $row->request_hash, $hash)) {
            return ApiResponse::error(ErrorCode::IdempotencyKeyReused);
        }

        if ($row->status !== 'COMPLETED') {
            // Conditional update: of two retries racing for an abandoned key, exactly one wins.
            $tookOver = DB::table('idempotency_keys')
                ->where('id', $row->id)
                ->where('status', 'IN_PROGRESS')
                ->where('updated_at', '<', now()->subMinutes(self::ABANDONED_AFTER_MINUTES))
                ->update(['updated_at' => now()]);

            return $tookOver === 1 ? (string) $row->id : ApiResponse::error(ErrorCode::IdempotencyInProgress);
        }

        $body = Crypt::decryptString((string) $row->response_body_encrypted);
        $headers = ['Idempotency-Replayed' => 'true'];

        // Replay the exact bytes; an empty body (e.g. 204) stays empty.
        return $body === ''
            ? new Response('', (int) $row->response_status, $headers)
            : JsonResponse::fromJsonString($body, (int) $row->response_status, $headers);
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
