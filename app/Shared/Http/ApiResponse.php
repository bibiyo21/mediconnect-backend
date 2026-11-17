<?php

namespace App\Shared\Http;

use App\Shared\Enums\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Context;

/**
 * The response envelope from CLAUDE.md §23 / api-specification.md §1.2.
 */
final class ApiResponse
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public static function success(mixed $data = null, array $meta = [], ?string $message = null, int $status = 200): JsonResponse
    {
        return new JsonResponse([
            'success' => true,
            'data' => $data ?? (object) [],
            'meta' => $meta === [] ? (object) [] : $meta,
            'message' => $message,
        ], $status);
    }

    /**
     * @param  array<string, list<string>>  $errors
     * @param  array<string, string>  $headers
     */
    public static function error(ErrorCode $code, ?string $message = null, array $errors = [], array $headers = []): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'message' => $message ?? $code->defaultMessage(),
            'errors' => $errors === [] ? (object) [] : $errors,
            'code' => $code->value,
            'request_id' => Context::get('request_id'),
        ], $code->httpStatus(), $headers);
    }
}
