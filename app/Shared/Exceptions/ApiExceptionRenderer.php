<?php

namespace App\Shared\Exceptions;

use App\Shared\Enums\ErrorCode;
use App\Shared\Http\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Maps every exception on an API request to the §23 error envelope.
 * Unknown failures become a generic 500: internal messages, SQL and stack
 * traces never reach the client (they are still reported to the logs).
 */
final class ApiExceptionRenderer
{
    public static function shouldRender(Request $request): bool
    {
        return $request->is('api/*') || $request->expectsJson();
    }

    public static function render(Throwable $e): JsonResponse
    {
        return match (true) {
            $e instanceof DomainException => ApiResponse::error($e->errorCode(), $e->clientMessage(), $e->errors(), reasonCode: $e->reasonCode()),
            $e instanceof ValidationException => ApiResponse::error(ErrorCode::ValidationFailed, null, $e->errors()),
            $e instanceof AuthenticationException => ApiResponse::error(ErrorCode::Unauthenticated),
            $e instanceof AuthorizationException => ApiResponse::error(ErrorCode::Forbidden),
            $e instanceof ModelNotFoundException => ApiResponse::error(ErrorCode::NotFound),
            $e instanceof HttpExceptionInterface => self::fromHttpException($e),
            default => ApiResponse::error(ErrorCode::ServerError),
        };
    }

    private static function fromHttpException(HttpExceptionInterface $e): JsonResponse
    {
        $status = $e->getStatusCode();

        $code = match (true) {
            $status === 401 => ErrorCode::Unauthenticated,
            $status === 403 => ErrorCode::Forbidden,
            $status === 404 => ErrorCode::NotFound,
            $status === 405 => ErrorCode::MethodNotAllowed,
            $status === 429 => ErrorCode::RateLimited,
            $status === 503 => ErrorCode::DependencyUnavailable,
            $status >= 500 => ErrorCode::ServerError,
            default => ErrorCode::BadRequest,
        };

        /** @var array<string, string> $headers */
        $headers = array_intersect_key($e->getHeaders(), array_flip(['Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining', 'Allow']));

        $response = ApiResponse::error($code, null, [], $headers);

        // Keep statuses the code table does not model exactly (e.g. 419 CSRF mismatch).
        return $response->setStatusCode($status);
    }
}
