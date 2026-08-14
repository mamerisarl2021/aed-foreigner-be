<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\ServiceResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class BaseController extends Controller
{
    protected $data = null;

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

    public function sendResponse(string $message, $data = null, int $code = 200): JsonResponse
    {
        $response = [
            'success' => true,
            'data' => $data,
            'message' => $message,
        ];

        return response()->json($response, $code);
    }

    public function sendPaginatedResponse(string $message, $data = null): JsonResponse
    {
        $response = [
            'success' => true,
            'data' => $data['data'],
            'pagination' => $data['pagination'],
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
}
