<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\IdentityReview\RejectIdentityRequest;
use App\Http\Requests\IdentityReview\RequestVisioEnrollmentRequest;
use App\Http\Requests\IdentityReview\SupervisorReturnEnrollmentRequest;
use App\Http\Resources\EnrollmentRequestResource;
use App\Models\EnrollmentRequest;
use App\Services\IdentityReview\IdentityReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IdentityReviewController extends BaseController
{
    public function __construct(
        private readonly IdentityReviewService $identityReview,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', EnrollmentRequest::class);
        $paginator = $this->identityReview->list($request);
        $paginator->getCollection()->transform(fn ($item) => new EnrollmentRequestResource($item));

        return $this->sendResponse(
            'Liste filtrée des demandes.',
            $paginator
        );
    }

    public function show(int $id): JsonResponse
    {
        $enrollment = EnrollmentRequest::findOrFail($id);
        $this->authorize('view', $enrollment);
        $result = $this->identityReview->show($id);
        if ($result->success) {
            return $this->sendResponse($result->message, new EnrollmentRequestResource($result->data));
        }

        return $this->sendError($result->message, $result->data ?? [], $result->code);
    }

    public function claim(Request $request, int $id): JsonResponse
    {
        $enrollment = EnrollmentRequest::findOrFail($id);
        $this->authorize('claim', $enrollment);

        $agentId = $request->user()?->id;
        if (! $agentId) {
            return $this->sendError('Non authentifié.', null, 401);
        }

        return $this->respond($this->identityReview->claim($id, (int) $agentId));
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $enrollment = EnrollmentRequest::findOrFail($id);
        $this->authorize('approve', $enrollment);

        $agentId = $request->user()?->id;
        if (! $agentId) {
            return $this->sendError('Non authentifié.', null, 401);
        }

        return $this->respond($this->identityReview->approve($id, (int) $agentId));
    }

    public function requestVisio(RequestVisioEnrollmentRequest $request, int $id): JsonResponse
    {
        $enrollment = EnrollmentRequest::findOrFail($id);
        $this->authorize('requestVisio', $enrollment);

        return $this->respond($this->identityReview->requestVisio(
            $id,
            $request->input('notes')
        ));
    }

    public function completeVisio(int $id): JsonResponse
    {
        $enrollment = EnrollmentRequest::findOrFail($id);
        $this->authorize('completeVisio', $enrollment);

        return $this->respond($this->identityReview->completeVisio($id));
    }

    public function supervisorApprove(Request $request, int $id): JsonResponse
    {
        $enrollment = EnrollmentRequest::findOrFail($id);
        $this->authorize('supervisorApprove', $enrollment);

        $supervisorId = $request->user()?->id;
        if (! $supervisorId) {
            return $this->sendError('Non authentifié.', null, 401);
        }

        return $this->respond($this->identityReview->supervisorApprove($id, (int) $supervisorId));
    }

    /**
     * Responsable confirms the agent's rejection proposal (PDF: Approbation du rejet).
     */
    public function supervisorReject(RejectIdentityRequest $request, int $id): JsonResponse
    {
        $enrollment = EnrollmentRequest::findOrFail($id);
        $this->authorize('supervisorReject', $enrollment);

        return $this->respond($this->identityReview->supervisorApproveReject(
            $id,
            $request->input('stage'),
            $request->input('reasons'),
            $request->input('comments'),
        ));
    }

    public function supervisorReturn(SupervisorReturnEnrollmentRequest $request, int $id): JsonResponse
    {
        $enrollment = EnrollmentRequest::findOrFail($id);
        $this->authorize('supervisorReturn', $enrollment);

        return $this->respond($this->identityReview->supervisorReturn(
            $id,
            $request->input('reasons'),
            $request->input('comments'),
        ));
    }

    /**
     * Agent proposes rejection — transmitted to responsable (not final).
     */
    public function reject(RejectIdentityRequest $request, int $id): JsonResponse
    {
        $enrollment = EnrollmentRequest::findOrFail($id);
        $this->authorize('reject', $enrollment);

        return $this->respond($this->identityReview->proposeReject(
            $id,
            $request->input('stage'),
            $request->input('reasons'),
            $request->input('comments'),
        ));
    }
}
