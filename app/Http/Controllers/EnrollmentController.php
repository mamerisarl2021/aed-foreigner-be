<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Enrollment\InstructionEnrollmentRequest;
use App\Http\Requests\Enrollment\ValidationEnrollmentRequest;
use App\Http\Resources\EnrollmentDecisionDetailResource;
use App\Http\Resources\EnrollmentDecisionListResource;
use App\Http\Resources\EnrollmentRequestAgentDetailResource;
use App\Http\Resources\EnrollmentRequestListResource;
use App\Models\EnrollmentRequest;
use App\Services\Enrollment\ForeignerEnrollmentService;
use App\Services\IdentityReview\IdentityReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Annotations as OA;

final class EnrollmentController extends BaseController
{
    public function __construct(
        private readonly ForeignerEnrollmentService $foreignerEnrollment,
        private readonly IdentityReviewService $reviewService,
    ) {}

    /**
     * @OA\Post(
     *      path="/api/v1/enrolements/etrangers",
     *      operationId="enrollmentStoreEtranger",
     *      tags={"Enrollment - Physique"},
     *      summary="Submit personne physique enrollment",
     *      description="Diagram §2.4. Requires OTP + KYC gates. Returns 202 with statut EN_ATTENTE.",
     *
     *      @OA\RequestBody(
     *          required=true,
     *
     *          @OA\MediaType(
     *              mediaType="multipart/form-data",
     *
     *              @OA\Schema(
     *                  required={"email", "phonenumber", "name", "first_name", "nationality"},
     *
     *                  @OA\Property(property="email", type="string", format="email"),
     *                  @OA\Property(property="phonenumber", type="string"),
     *                  @OA\Property(property="name", type="string"),
     *                  @OA\Property(property="first_name", type="string"),
     *                  @OA\Property(property="sexe", type="string"),
     *                  @OA\Property(property="date_of_birth", type="string", format="date"),
     *                  @OA\Property(property="place_of_birth", type="string"),
     *                  @OA\Property(property="nationality", type="string"),
     *                  @OA\Property(property="country_of_residence", type="string"),
     *                  @OA\Property(property="address", type="string"),
     *                  @OA\Property(property="document_type", type="string"),
     *                  @OA\Property(property="document_number", type="string"),
     *                  @OA\Property(property="selfie", type="string", format="binary"),
     *                  @OA\Property(property="recto", type="string", format="binary"),
     *                  @OA\Property(property="verso", type="string", format="binary"),
     *                  @OA\Property(property="profile", type="string", format="binary")
     *              )
     *          )
     *      ),
     *
     *      @OA\Response(
     *          response=202,
     *          description="Demande acceptée",
     *
     *          @OA\JsonContent(
     *
     *              @OA\Property(property="success", type="boolean", example=true),
     *              @OA\Property(
     *                  property="data",
     *                  type="object",
     *                  @OA\Property(property="demande_id", type="integer"),
     *                  @OA\Property(property="statut", type="string", example="EN_ATTENTE")
     *              )
     *          )
     *      ),
     *
     *      @OA\Response(response=400, description="OTP or KYC not verified"),
     *      @OA\Response(response=422, description="Validation error")
     * )
     */
    public function storeEtranger(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'phonenumber' => 'required|string',
            'name' => 'required|string',
            'first_name' => 'required|string',
            'sexe' => 'nullable|string',
            'date_of_birth' => 'nullable|date',
            'place_of_birth' => 'nullable|string',
            'nationality' => 'required|string',
            'country_of_residence' => 'nullable|string',
            'address' => 'nullable|string',
            'document_type' => 'nullable|string',
            'document_number' => 'nullable|string',
        ]);

        return $this->respond($this->foreignerEnrollment->submitEnrollment($request));
    }

    /**
     * @OA\Get(
     *      path="/api/v1/enrolements",
     *      operationId="enrollmentList",
     *      tags={"Enrollment - Physique"},
     *      summary="List enrollment requests",
     *      description="Diagram §3.1/§3.2. Filter by statut (supports pipe: VALIDATION_AGENT|REJET_AGENT). Default EN_ATTENTE for agent; VALIDATION_AGENT|REJET_AGENT for responsable.",
     *      security={{"sanctum":{}}},
     *
     *      @OA\Parameter(
     *          name="statut",
     *          in="query",
     *          description="Status filter (alias: status). Default EN_ATTENTE.",
     *
     *          @OA\Schema(type="string", example="EN_ATTENTE")
     *      ),
     *
     *      @OA\Parameter(name="type", in="query", @OA\Schema(type="string", enum={"PERSONNE_PHYSIQUE", "PERSONNE_MORALE"})),
     *      @OA\Parameter(name="q", in="query", @OA\Schema(type="string")),
     *      @OA\Parameter(name="from", in="query", @OA\Schema(type="string", format="date")),
     *      @OA\Parameter(name="to", in="query", @OA\Schema(type="string", format="date")),
     *      @OA\Parameter(name="per_page", in="query", @OA\Schema(type="integer", default=15)),
     *      @OA\Parameter(name="order_by", in="query", @OA\Schema(type="string", enum={"id", "created_at"})),
     *      @OA\Parameter(name="order_dir", in="query", @OA\Schema(type="string", enum={"asc", "desc"})),
     *
     *      @OA\Response(response=200, description="Paginated list"),
     *      @OA\Response(response=401, description="Unauthenticated"),
     *      @OA\Response(response=403, description="Forbidden")
     * )
     */
    public function index(Request $request): JsonResponse
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
     * @OA\Get(
     *      path="/api/v1/enrolements/{id}",
     *      operationId="enrollmentShow",
     *      tags={"Enrollment - Physique"},
     *      summary="Enrollment request detail (agent / responsable backoffice)",
     *      description="Agent: EnrollmentRequestAgentDetailResource. Responsable: EnrollmentDecisionDetailResource with decision_agent block. Same route for physique and morale.",
     *      security={{"sanctum":{}}},
     *
     *      @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *
     *      @OA\Response(response=200, description="Detail demande"),
     *      @OA\Response(response=404, description="Not found")
     * )
     */
    public function show(int $id): JsonResponse
    {
        $enrollment = EnrollmentRequest::findOrFail($id);
        $this->authorize('view', $enrollment);
        $result = $this->reviewService->show($id);
        $user = auth()->user();
        $resource = $user && $user->hasRole(config('roles.responsable_de_validation'))
            ? new EnrollmentDecisionDetailResource($result->data)
            : new EnrollmentRequestAgentDetailResource($result->data);

        return $this->sendResponse($result->message, $resource);
    }

    /**
     * @OA\Patch(
     *      path="/api/v1/enrolements/{id}/prise-en-charge",
     *      operationId="enrollmentPriseEnCharge",
     *      tags={"Enrollment - Physique"},
     *      summary="Agent self-assign (prise en charge)",
     *      description="Agent only. EN_ATTENTE and unassigned requests only.",
     *      security={{"sanctum":{}}},
     *
     *      @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *
     *      @OA\Response(response=200, description="Demande prise en charge"),
     *      @OA\Response(response=422, description="Already assigned or wrong status")
     * )
     */
    public function priseEnCharge(int $id): JsonResponse
    {
        $enrollment = EnrollmentRequest::findOrFail($id);
        $this->authorize('claim', $enrollment);

        $result = $this->reviewService->claim($id, (string) auth()->id());

        return $this->sendResponse($result->message, new EnrollmentRequestAgentDetailResource($result->data));
    }

    /**
     * @OA\Patch(
     *      path="/api/v1/enrolements/{id}/prise-en-charge-validation",
     *      operationId="enrollmentPriseEnChargeValidation",
     *      tags={"Enrollment - Physique"},
     *      summary="Responsable self-assign (prise en charge décision)",
     *      description="Responsable only. VALIDATION_AGENT or REJET_AGENT and unassigned decisions only.",
     *      security={{"sanctum":{}}},
     *
     *      @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *
     *      @OA\Response(response=200, description="Décision prise en charge"),
     *      @OA\Response(response=422, description="Already assigned or wrong status")
     * )
     */
    public function priseEnChargeValidation(int $id): JsonResponse
    {
        $enrollment = EnrollmentRequest::findOrFail($id);
        $this->authorize('claimValidation', $enrollment);

        $result = $this->reviewService->claimValidation($id, (string) auth()->id());

        return $this->sendResponse($result->message, new EnrollmentDecisionDetailResource($result->data));
    }

    /**
     * @OA\Patch(
     *      path="/api/v1/enrolements/{id}/instruction",
     *      operationId="enrollmentInstruction",
     *      tags={"Enrollment - Physique"},
     *      summary="Agent instruction (validate or reject)",
     *      description="Diagram §3.1. From EN_ATTENTE → VALIDATION_AGENT or REJET_AGENT. Agent must have prise en charge first.",
     *      security={{"sanctum":{}}},
     *
     *      @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *
     *      @OA\RequestBody(
     *          required=true,
     *
     *          @OA\JsonContent(
     *              required={"statut"},
     *
     *              @OA\Property(property="statut", type="string", enum={"VALIDATION_AGENT", "REJET_AGENT"}),
     *              @OA\Property(property="motif", type="array", @OA\Items(type="string")),
     *              @OA\Property(property="commentaire", type="string")
     *          )
     *      ),
     *
     *      @OA\Response(response=200, description="Instruction enregistrée"),
     *      @OA\Response(response=422, description="Statut non éligible")
     * )
     */
    public function instruction(InstructionEnrollmentRequest $request, int $id): JsonResponse
    {
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
     * @OA\Patch(
     *      path="/api/v1/enrolements/{id}/validation",
     *      operationId="enrollmentValidation",
     *      tags={"Enrollment - Physique"},
     *      summary="Responsable validation decision",
     *      description="Diagram §3.2. Requires prise en charge validation first. VALIDATION_AGENT: APPROUVEE or RETOUR_AGENT. REJET_AGENT: REJET_CONFIRME or RETOUR_AGENT.",
     *      security={{"sanctum":{}}},
     *
     *      @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *
     *      @OA\RequestBody(
     *          required=true,
     *
     *          @OA\JsonContent(
     *              required={"decision"},
     *
     *              @OA\Property(property="decision", type="string", enum={"APPROUVEE", "REJET_CONFIRME", "RETOUR_AGENT"}),
     *              @OA\Property(property="commentaire", type="string"),
     *              @OA\Property(
     *                  property="motif",
     *                  type="array",
     *                  description="Required when decision is RETOUR_AGENT",
     *
     *                  @OA\Items(type="string")
     *              )
     *          )
     *      ),
     *
     *      @OA\Response(response=200, description="Décision enregistrée"),
     *      @OA\Response(response=422, description="Statut non éligible")
     * )
     */
    public function validation(ValidationEnrollmentRequest $request, int $id): JsonResponse
    {
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
