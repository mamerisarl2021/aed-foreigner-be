<?php

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
            return $this->sendResponse($result->message, $result->data);
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

    public function sendResponse(string $message, $data = null)
    {
        $response = [
            'success' => true,
            'data' => $data,
            'message' => $message,
        ];

        return response()->json($response, 200);
    }

    public function sendPaginatedResponse(string $message, $data = null)
    {
        $response = [
            'success' => true,
            'data' => $data['data'],
            'pagination' => $data['pagination'],
            'message' => $message,
        ];

        return response()->json($response, 200);
    }

    public function sendError($error, $errorMessages = [], $code = 400)
    {
        $response = [
            'success' => false,
            'message' => $error,
            'status' => $code,
        ];

        if (! empty($errorMessages)) {
            $response['data'] = $errorMessages;
        }
        Log::error('Error occurred:', ['error' => $error, 'errorMessages' => $errorMessages, 'code' => $code]);

        return response()->json($response, $code);
    }
}
