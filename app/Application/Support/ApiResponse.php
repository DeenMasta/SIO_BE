<?php

namespace App\Application\Support;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;

final class ApiResponse
{
    public static function success(mixed $data = null, string $message = 'OK', int $status = 200, array $meta = []): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'message' => $message,
            'data' => $data,
            'meta' => $meta,
        ], $status);
    }

    public static function error(string $message, int $status = 400, array $errors = [], array $meta = []): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'message' => $message,
            'errors' => $errors,
            'meta' => $meta,
        ], $status);
    }

    public static function paginated(LengthAwarePaginator $records, mixed $data, string $message = 'OK', int $status = 200, array $meta = []): JsonResponse
    {
        return self::success(
            $data,
            $message,
            $status,
            array_merge($meta, [
                'pagination' => [
                    'current_page' => $records->currentPage(),
                    'per_page' => $records->perPage(),
                    'total' => $records->total(),
                    'last_page' => $records->lastPage(),
                ],
            ]),
        );
    }
}
