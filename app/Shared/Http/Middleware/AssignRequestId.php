<?php

namespace App\Shared\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Correlation ID for every request (api-specification.md §1.4).
 *
 * A client-supplied X-Request-Id is kept only if it is a ULID or UUID, so
 * arbitrary text cannot be injected into logs. The ID is added to Laravel's
 * Context, which attaches it to every log entry and to queued jobs dispatched
 * during the request, and is echoed back in the response header.
 */
final class AssignRequestId
{
    public const HEADER = 'X-Request-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $incoming = (string) $request->headers->get(self::HEADER, '');
        $requestId = (Str::isUlid($incoming) || Str::isUuid($incoming)) ? $incoming : (string) Str::ulid();

        Context::add('request_id', $requestId);

        $response = $next($request);
        $response->headers->set(self::HEADER, $requestId);

        return $response;
    }
}
