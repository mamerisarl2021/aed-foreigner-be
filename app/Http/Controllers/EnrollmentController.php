<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Enrollment\ClaimEnrollmentRequest;
use App\Http\Requests\Enrollment\ClaimValidationEnrollmentRequest;
use App\Http\Requests\Enrollment\InstructionEnrollmentRequest;
use App\Http\Requests\Enrollment\ListEnrollmentRequestsRequest;
use App\Http\Requests\Enrollment\ShowEnrollmentRequest;
use App\Http\Requests\Enrollment\SubmitEnrollmentRequest;
use App\Http\Requests\Enrollment\ValidationEnrollmentRequest;
use App\Http\Resources\EnrollmentDecisionDetailResource;
use App\Http\Resources\EnrollmentDecisionListResource;
use App\Http\Resources\EnrollmentRequestAgentDetailResource;
use App\Http\Resources\EnrollmentRequestListResource;
use App\Models\EnrollmentRequest;
use App\Services\Enrollment\ForeignerEnrollmentService;
use App\Services\IdentityReview\IdentityReviewService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Illuminate\Http\JsonResponse;

#[Group('Enrollment - Physique')]
final class EnrollmentController extends BaseController
{
    public function __construct(
        private readonly ForeignerEnrollmentService $foreignerEnrollment,
        private readonly IdentityReviewService $reviewService,
    ) {}

    /**
     * Submit personne physique enrollment
     *
     * Diagram §2.4. Requires OTP + KYC gates. Full identity data and documents
     * (selfie, recto; verso optional) are required at submit time.
     * Success 202 data: demande_id (UUID), numero_suivi (tracking code PK…), statut EN_ATTENTE.
     * phonenumber: optional leading +, then 8–20 digits; spaces/dashes/parentheses allowed and stripped.
     */
    public function storeEtranger(SubmitEnrollmentRequest $request): JsonResponse
    {
        return $this->respond($this->foreignerEnrollment->submitEnrollment($request));
    }

    /**
     * List enrollment requests
     *
     * Diagram §3.1/§3.2. Filter by statut (supports pipe: VALIDATION_AGENT|REJET_AGENT).
     * Default EN_ATTENTE for agent; VALIDATION_AGENT|REJET_AGENT for responsable.
     * Defaults: per_page=15 (max 100), order_by=created_at, order_dir=desc.
     */
    public function index(ListEnrollmentRequestsRequest $request): JsonResponse
    {
        $this->authorize('viewAny', EnrollmentRequest::class);
        $paginator = $this->reviewService->list($request);
        $user = $request->user();
        $resourceClass = $user && $user->hasRole(config('roles.responsable_de_validation'))
            ? EnrollmentDecisionListResource::class
            : EnrollmentRequestListResource::class;
        $paginator->getCollection()->transform(fn ($item) => new $resourceClass($item));

        return $this->sendResponse('Liste des demandes.', $paginator);
    }

    /**
     * Enrollment request detail (agent / responsable backoffice)
     *
     * Agent: EnrollmentRequestAgentDetailResource. Responsable: EnrollmentDecisionDetailResource
     * with decision_agent block. Same route for physique and morale.
     */
    #[PathParameter('id', description: 'Enrollment request UUID.', type: 'string', format: 'uuid')]
    public function show(ShowEnrollmentRequest $request): JsonResponse
    {
        $id = (string) $request->validated('id');
        $enrollment = EnrollmentRequest::findOrFail($id);
        $this->authorize('view', $enrollment);
        $result = $this->reviewService->show($id);
        $user = $request->user();
        $resource = $user && $user->hasRole(config('roles.responsable_de_validation'))
            ? new EnrollmentDecisionDetailResource($result->data)
            : new EnrollmentRequestAgentDetailResource($result->data);

        return $this->sendResponse($result->message, $resource);
    }

    /**
     * Agent self-assign (prise en charge)
     *
     * Agent only. EN_ATTENTE and unassigned requests only.
     */
    #[PathParameter('id', description: 'Enrollment request UUID.', type: 'string', format: 'uuid')]
    public function priseEnCharge(ClaimEnrollmentRequest $request): JsonResponse
    {
        $id = (string) $request->validated('id');
        $enrollment = EnrollmentRequest::findOrFail($id);
        $this->authorize('claim', $enrollment);

        $result = $this->reviewService->claim($id, (string) $request->user()?->id);

        return $this->sendResponse($result->message, new EnrollmentRequestAgentDetailResource($result->data));
    }

    /**
     * Responsable self-assign (prise en charge décision)
     *
     * Responsable only. VALIDATION_AGENT or REJET_AGENT and unassigned decisions only.
     */
    #[PathParameter('id', description: 'Enrollment request UUID.', type: 'string', format: 'uuid')]
    public function priseEnChargeValidation(ClaimValidationEnrollmentRequest $request): JsonResponse
    {
        $id = (string) $request->validated('id');
        $enrollment = EnrollmentRequest::findOrFail($id);
        $this->authorize('claimValidation', $enrollment);

        $result = $this->reviewService->claimValidation($id, (string) $request->user()?->id);

        return $this->sendResponse($result->message, new EnrollmentDecisionDetailResource($result->data));
    }

    /**
     * Agent instruction (validate or reject)
     *
     * Diagram §3.1. From EN_ATTENTE to VALIDATION_AGENT or REJET_AGENT.
     * Agent must have prise en charge first.
     */
    #[PathParameter('id', description: 'Enrollment request UUID.', type: 'string', format: 'uuid')]
    public function instruction(InstructionEnrollmentRequest $request): JsonResponse
    {
        $id = (string) $request->validated('id');
        $enrollment = EnrollmentRequest::findOrFail($id);
        $this->authorize('instruction', $enrollment);

        return $this->respond($this->reviewService->instruction(
            $id,
            $request->input('statut'),
            $request->input('motif'),
            $request->input('commentaire'),
        ));
    }

    /**
     * Responsable validation decision
     *
     * Diagram §3.2. Requires prise en charge validation first.
     * VALIDATION_AGENT: APPROUVEE or RETOUR_AGENT. REJET_AGENT: REJET_CONFIRME or RETOUR_AGENT.
     */
    #[PathParameter('id', description: 'Enrollment request UUID.', type: 'string', format: 'uuid')]
    public function validation(ValidationEnrollmentRequest $request): JsonResponse
    {
        $id = (string) $request->validated('id');
        $enrollment = EnrollmentRequest::findOrFail($id);
        $this->authorize('validation', $enrollment);

        $result = $this->reviewService->validation(
            $id,
            $request->input('decision'),
            $request->input('commentaire'),
            (string) $request->user()?->id,
            $request->input('motif'),
        );

        if (! $result->success) {
            return $this->respond($result);
        }

        $enrollment = EnrollmentRequest::with(['assignedAgent', 'assignedResponsable', 'submittedBy'])->findOrFail($id);

        return $this->sendResponse($result->message, new EnrollmentDecisionDetailResource($enrollment));
    }
}
