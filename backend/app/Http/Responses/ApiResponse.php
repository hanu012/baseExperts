<?php

namespace App\Http\Responses;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;

/**
 * Builds the project-wide API envelope: { success, data, error }.
 *
 * Every API endpoint returns through here so clients can rely on one shape.
 * `error` is null on success; `data` is null on failure.
 */
class ApiResponse
{
    public static function success(mixed $data = null, int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $data,
            'error' => null,
        ], $status);
    }

    /**
     * Paginated list response. Keeps items under `data` and paging metadata
     * alongside it, so list endpoints stay consistent with single-resource ones.
     */
    public static function paginated(LengthAwarePaginator $paginator, int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
            'error' => null,
        ], $status);
    }

    public static function error(string $code, string $message, int $status = 400): JsonResponse
    {
        return response()->json([
            'success' => false,
            'data' => null,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], $status);
    }
}
