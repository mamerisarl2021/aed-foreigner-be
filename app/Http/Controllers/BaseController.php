<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Log;

class BaseController extends Controller
{
    protected $data = null;

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
