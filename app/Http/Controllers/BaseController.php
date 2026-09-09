<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\ServiceResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;

class BaseController extends Controller
{
    protected function respond(ServiceResult $result): JsonResponse
    {
        if ($result->success) {
            return $this->sendResponse($result->message, $result->data, $result->code);
        }

        return $this->sendError($result->message, $result->data, $result->code);
    }

    protected function respondPaginated(ServiceResult $result): JsonResponse
    {
        if ($result->success) {
            return $this->sendPaginatedResponse($result->message, $result->data);
        }

        return $this->sendError($result->message, $result->data, $result->code);
    }

    public function sendResponse(?string $message, mixed $data = null, int $code = 200): JsonResponse
    {
        $response = [
            'success' => true,
            'data' => $data,
            'message' => $message,
        ];

        return response()->json($response, $code);
    }

    /**
     * @param  array{data: mixed, pagination: array<string, mixed>}|null  $data
     */
    public function sendPaginatedResponse(string $message, ?array $data = null): JsonResponse
    {
        $response = [
            'success' => true,
            'data' => $data['data'] ?? null,
            'pagination' => $data['pagination'] ?? null,
            'message' => $message,
        ];

        return response()->json($response, 200);
    }

    public function sendError(string $error, mixed $errorMessages = [], int $code = 400): JsonResponse
    {
        $response = [
            'success' => false,
            'message' => $error,
            'status' => $code,
        ];

        if (! empty($errorMessages)) {
            $response['data'] = $errorMessages;
        }

        if ($code >= 500) {
            Log::error('Error occurred:', ['error' => $error, 'errorMessages' => $errorMessages, 'code' => $code]);
        }

        return response()->json($response, $code);
    }

    /**
     * @param  LengthAwarePaginator<int, mixed>  $paginator
     * @return array{data: mixed, pagination: array<string, mixed>}
     */
    protected function flattenPagination(LengthAwarePaginator $paginator): array
    {
        $flattenedData = $paginator->toArray();
        $data = $flattenedData['data'];
        unset($flattenedData['data']);

        return ['data' => $data, 'pagination' => $flattenedData];
    }
}
