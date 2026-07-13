<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Signature\StoreMultipleSignatureRequest;
use App\Models\Signature;
use App\Models\SignatureDocument;
use App\Models\Stamp;
use App\Services\SignatureService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SignatureController extends BaseController
{
    public function __construct(
        private readonly SignatureService $signatureService,
    ) {}

    public function store(Request $request, string $userId, string $documentId): JsonResponse
    {
        $authId = (string) $request->user()->id;
        $result = $this->signatureService->store($userId, $documentId, $authId);

        if (! $result->success) {
            return $this->sendError($result->message, $result->data ?? [], $result->code);
        }

        return $this->sendResponse($result->message, $result->data ?? []);
    }

    public function storeMultiple(StoreMultipleSignatureRequest $request, string $documentId): JsonResponse
    {
        $users = $request->validated('users');
        $authId = (string) $request->user()->id;
        $result = $this->signatureService->storeMultiple($documentId, $users, $authId);

        if (! $result->success) {
            return $this->sendError($result->message, $result->data ?? [], $result->code);
        }

        return $this->sendResponse($result->message, $result->data ?? []);
    }

    public function init(SignatureDocument $signature_document, string $token): JsonResponse
    {
        $result = $this->signatureService->init($signature_document, $token);

        if (! $result->success) {
            return $this->sendError($result->message, $result->data ?? [], $result->code);
        }

        return $this->sendResponse($result->message, $result->data ?? []);
    }

    public function initWithPosition(SignatureDocument $signature_document, Signature $signature, string $token, Stamp $stamp, Request $request): JsonResponse
    {
        $preferences = [
            'show_name' => (bool) $request->input('show_name', false),
            'show_location' => (bool) $request->input('show_location', false),
            'show_date' => (bool) $request->input('show_date', false),
        ];

        $user = $request->user();

        $result = $this->signatureService->initWithPosition($signature_document, $signature, $token, $stamp, $preferences, $user);

        if (! $result->success) {
            return $this->sendError($result->message, $result->data ?? [], $result->code);
        }

        return $this->sendResponse($result->message, $result->data ?? []);
    }

    public function sign(Request $request, SignatureDocument $signature_document, string $processId, string $token): JsonResponse
    {
        $authId = (string) $request->user()->id;
        $result = $this->signatureService->sign($signature_document, $processId, $token, $authId);

        if (! $result->success) {
            return $this->sendError($result->message, $result->data ?? [], $result->code);
        }

        return $this->sendResponse($result->message, $result->data ?? []);
    }

    public function timestampDocument(Request $request): JsonResponse
    {
        try {
            $filePath = $request->input('file_path');
            if (! $filePath) {
                return $this->sendError('file_path is required', [], 400);
            }
            $response = $this->signatureService->timestampDocument($filePath);

            return $this->sendResponse('Document timestamped successfully.', ['response' => $response]);
        } catch (Exception $e) {
            return $this->sendError('Échec du timestamping.', [$e->getMessage()], 500);
        }
    }

    public function decline(Request $request, SignatureDocument $signature_document): JsonResponse
    {
        $authId = (string) $request->user()->id;
        $result = $this->signatureService->decline($signature_document, $authId);

        if (! $result->success) {
            return $this->sendError($result->message, $result->data ?? [], $result->code);
        }

        return $this->sendResponse($result->message, $result->data ?? []);
    }
}
