<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\IdentityReview\RejectIdentityRequest;
use App\Http\Resources\IdentityResource;
use App\Services\IdentityReview\IdentityReviewService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class IdentityReviewController extends BaseController
{
    public function __construct(
        private readonly IdentityReviewService $identityReview,
    ) {}

    public function index(Request $request): \Illuminate\Http\JsonResponse
    {
        $paginator = $this->identityReview->list($request);
        $paginator->getCollection()->transform(fn ($item) => new IdentityResource($item));
        
        return $this->sendResponse(
            'Liste filtrée des demandes.',
            $paginator
        );
    }

    public function show(int $id): \Illuminate\Http\JsonResponse
    {
        $result = $this->identityReview->show($id);
        if ($result->success) {
            return $this->sendResponse($result->message, new IdentityResource($result->data));
        }
        return $this->sendError($result->message, $result->data ?? [], $result->code);
    }

    public function claim(int $id): \Illuminate\Http\JsonResponse
    {
        $agentId = Auth::id();
        if (! $agentId) {
            return $this->sendError('Non authentifié.', null, 401);
        }

        return $this->respond($this->identityReview->claim($id, $agentId));
    }

    public function approve(Request $request, int $id): \Illuminate\Http\JsonResponse
    {
        $agentId = Auth::id();
        if (! $agentId) {
            return $this->sendError('Non authentifié.', null, 401);
        }

        return $this->respond($this->identityReview->approve($id, $agentId));
    }

    public function supervisorApprove(Request $request, int $id): \Illuminate\Http\JsonResponse
    {
        $supervisorId = Auth::id();
        if (! $supervisorId) {
            return $this->sendError('Non authentifié.', null, 401);
        }

        return $this->respond($this->identityReview->supervisorApprove($id, $supervisorId));
    }

    public function supervisorReject(RejectIdentityRequest $request, int $id): \Illuminate\Http\JsonResponse
    {
        return $this->respond($this->identityReview->reject(
            $id,
            $request->input('stage'),
            $request->input('reasons'),
            $request->input('comments'),
            'APPROVED_BY_AGENT',
        ));
    }

    public function reject(RejectIdentityRequest $request, int $id): \Illuminate\Http\JsonResponse
    {
        return $this->respond($this->identityReview->reject(
            $id,
            $request->input('stage'),
            $request->input('reasons'),
            $request->input('comments'),
            'PENDING',
        ));
    }
}
