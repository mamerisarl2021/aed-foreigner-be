<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DataTransferObjects\EnrollmentListFilters;
use App\Http\Requests\Enrollment\ClaimEnrollmentRequest;
use App\Http\Requests\Enrollment\ClaimValidationEnrollmentRequest;
use App\Http\Requests\Enrollment\InstructionEnrollmentRequest;
use App\Http\Requests\Enrollment\ListEnrollmentRequestsRequest;
use App\Http\Requests\Enrollment\ShowEnrollmentRequest;
use App\Http\Requests\Enrollment\SubmitEnrollmentRequest;
use App\Http\Requests\Enrollment\ValidationEnrollmentRequest;
use App\Http\Resources\EnrollmentDecisionDetailResource;
use App\Http\Resources\EnrollmentRequestAgentDetailResource;
use App\Http\Resources\EnrollmentSubmitResource;
use App\Models\EnrollmentRequest;
use App\Models\User;
use App\Services\Enrollment\ForeignerEnrollmentService;
use App\Services\IdentityReview\AgentEnrollmentReviewService;
use App\Services\IdentityReview\EnrollmentReviewQueryService;
use App\Services\IdentityReview\SupervisorEnrollmentReviewService;
use App\Support\EnrollmentReviewResources;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Illuminate\Http\JsonResponse;

#[Group('Enrollment - Physique')]
final class EnrollmentController extends BaseController
{
    public function __construct(
        private readonly ForeignerEnrollmentService $foreignerEnrollment,
        private readonly EnrollmentReviewQueryService $reviewQuery,
        private readonly AgentEnrollmentReviewService $agentReview,
        private readonly SupervisorEnrollmentReviewService $supervisorReview,
    ) {}

    /**
     * Submit personne physique enrollment
     *
     * Diagram §2.4. Requires OTP + KYC gates. Full identity data and documents
     * (selfie, recto; verso optional) are required at submit time.
     * Optional capture_le (ISO-8601 with timezone, last 60 min / next 5 min) is stored on
     * selfie_captured_at for analyse_kyc.selfie.capture_le if omitted at KYC.
     * Success 202 data: demande_id (UUID), numero_suivi (tracking code PK…), statut EN_ATTENTE_AGENT,
     * email_verifie and telephone_verifie (always true: both OTPs are required before submit).
     * phonenumber: optional leading +, then 8–20 digits; spaces/dashes/parentheses allowed and stripped.
     */
    public function storeEtranger(SubmitEnrollmentRequest $request): JsonResponse
    {
        $result = $this->foreignerEnrollment->submitEnrollment($request);
        if (! $result->success) {
            return $this->respond($result);
        }

        return $this->sendResponse($result->message, new EnrollmentSubmitResource($result->data), $result->code);
    }

    /**
     * List enrollment requests
     *
     * Diagram §3.1/§3.2. Filter by statut (supports pipe: EN_ATTENTE_AGENT|EN_COURS_AGENT).
     * Default EN_ATTENTE_AGENT|EN_COURS_AGENT for agent; EN_ATTENTE_RESPONSABLE|EN_COURS_RESPONSABLE
     * for responsable. `avis` (FAVORABLE|DEFAVORABLE) filters on the agent's opinion, which is
     * carried by its own field and no longer by the status.
     * Every row exposes `statut` (machine, positional) and `statut_libelle`, worded for the
     * caller's role: a request awaiting the responsable never reads as approved or rejected.
     * Personne morale rows also expose `raison_sociale`, `pays_origine`, and `numero_suivi`
     * (null on physique except `numero_suivi` when a tracking code exists).
     * Defaults: per_page=15 (max 100), order_by=created_at, order_dir=desc.
     */
    public function index(ListEnrollmentRequestsRequest $request): JsonResponse
    {
        $this->authorize('viewAny', EnrollmentRequest::class);
        $paginator = $this->reviewQuery->list(
            EnrollmentListFilters::fromValidated($request->validated(), $request->user())
        );
        /** @var User $user */
        $user = $request->user();
        $resourceClass = EnrollmentReviewResources::listClass($user);
        $paginator->getCollection()->transform(fn ($item) => new $resourceClass($item));

        return $this->sendResponse('Liste des demandes.', $paginator);
    }

    /**
     * Enrollment request detail (agent / responsable backoffice)
     *
     * Agent: EnrollmentRequestAgentDetailResource. Responsable: EnrollmentDecisionDetailResource
     * with a decision_agent block whose `avis` is null until the agent has actually ruled.
     * Same route for physique and morale.
     *
     * Personne morale detail includes `similar_enrollments` (cross-check already computed on show)
     * and `numero_suivi`. The same `similar_enrollments` key is present on physique detail (often empty).
     * Responsable detail also exposes `numero_suivi` for the breadcrumb.
     *
     * Personne physique `analyse_kyc`: legacy `liveness`, `similarity`, `risk_score`, `details`
     * plus `similarity_percent` (0–100 or null), `document_identite` (OCR only — every
     * extracted text field, never form `kyc_data`; `verifie` is true only when
     * `doc_validity` is true and `details.error` is absent),
     * `selfie.url` (temporary cloud URL) / `selfie.capture_le` (ISO-8601 instant of the
     * selfie / liveness capture from `enrollment_requests.selfie_captured_at`: client
     * `capture_le` within the KYC window, else verification time),
     * and `etapes` booleans. `etapes.liveness_effectue` is true only when Face API
     * confirmed liveness (status `0`). `etapes.visage_compare` is a boolean.
     */
    #[PathParameter('id', description: 'Enrollment request UUID.', type: 'string', format: 'uuid')]
    public function show(ShowEnrollmentRequest $request): JsonResponse
    {
        $id = (string) $request->validated('id');
        $enrollment = EnrollmentRequest::query()->findOrFail($id);
        $this->authorize('view', $enrollment);
        $result = $this->reviewQuery->show($enrollment);

        /** @var User $user */
        $user = $request->user();

        return $this->sendResponse($result->message, EnrollmentReviewResources::detail($user, $result->data));
    }

    /**
     * Agent self-assign (prise en charge)
     *
     * Agent only. EN_ATTENTE_AGENT and unassigned requests only; moves the request
     * to EN_COURS_AGENT so the queue shows it as taken.
     */
    #[PathParameter('id', description: 'Enrollment request UUID.', type: 'string', format: 'uuid')]
    public function priseEnCharge(ClaimEnrollmentRequest $request): JsonResponse
    {
        $id = (string) $request->validated('id');
        $enrollment = EnrollmentRequest::query()->findOrFail($id);
        $this->authorize('claim', $enrollment);

        $result = $this->agentReview->claim($enrollment, (string) $request->user()?->id);

        return $this->sendResponse($result->message, new EnrollmentRequestAgentDetailResource($result->data));
    }

    /**
     * Responsable self-assign (prise en charge décision)
     *
     * Responsable only. EN_ATTENTE_RESPONSABLE and unassigned decisions only; moves the
     * request to EN_COURS_RESPONSABLE, which is what unlocks PATCH .../validation.
     */
    #[PathParameter('id', description: 'Enrollment request UUID.', type: 'string', format: 'uuid')]
    public function priseEnChargeValidation(ClaimValidationEnrollmentRequest $request): JsonResponse
    {
        $id = (string) $request->validated('id');
        $enrollment = EnrollmentRequest::query()->findOrFail($id);
        $this->authorize('claimValidation', $enrollment);

        $result = $this->supervisorReview->claimValidation($enrollment, (string) $request->user()?->id);

        return $this->sendResponse($result->message, new EnrollmentDecisionDetailResource($result->data));
    }

    /**
     * Agent instruction (avis favorable or défavorable)
     *
     * Diagram §3.1. From EN_COURS_AGENT to EN_ATTENTE_RESPONSABLE, recording the agent's
     * `avis` (FAVORABLE|DEFAVORABLE) in its own field. The agent gives an opinion, not a
     * verdict: the request is never approved or rejected at this step.
     * Agent must have prise en charge first. `motif[]` (UUIDs) is required when avis=DEFAVORABLE.
     */
    #[PathParameter('id', description: 'Enrollment request UUID.', type: 'string', format: 'uuid')]
    public function instruction(InstructionEnrollmentRequest $request): JsonResponse
    {
        $id = (string) $request->validated('id');
        $enrollment = EnrollmentRequest::query()->findOrFail($id);
        $this->authorize('instruction', $enrollment);

        $validated = $request->validated();
        $result = $this->agentReview->instruction(
            $enrollment,
            (string) $validated['avis'],
            $validated['motif'] ?? null,
            $validated['commentaire'] ?? null,
        );
        if (! $result->success) {
            return $this->respond($result);
        }

        return $this->sendResponse($result->message, new EnrollmentSubmitResource($result->data));
    }

    /**
     * Responsable validation decision
     *
     * Diagram §3.2. Requires EN_COURS_RESPONSABLE (prise en charge validation) first.
     * avis_agent=FAVORABLE: APPROUVEE or RETOUR_AGENT.
     * avis_agent=DEFAVORABLE: REJET_CONFIRME or RETOUR_AGENT.
     * RETOUR_AGENT clears the agent's avis: the request goes back to EN_ATTENTE_AGENT
     * with no decision at any level.
     */
    #[PathParameter('id', description: 'Enrollment request UUID.', type: 'string', format: 'uuid')]
    public function validation(ValidationEnrollmentRequest $request): JsonResponse
    {
        $id = (string) $request->validated('id');
        $enrollment = EnrollmentRequest::query()->findOrFail($id);
        $this->authorize('validation', $enrollment);

        $validated = $request->validated();

        $result = $this->supervisorReview->validation(
            $enrollment,
            (string) $validated['decision'],
            $validated['commentaire'] ?? null,
            (string) $request->user()?->id,
            $validated['motif'] ?? null,
        );

        if (! $result->success) {
            return $this->respond($result);
        }

        $enrollment = $this->reviewQuery->reloadForDecision($id);

        return $this->sendResponse($result->message, new EnrollmentDecisionDetailResource($enrollment));
    }
}
