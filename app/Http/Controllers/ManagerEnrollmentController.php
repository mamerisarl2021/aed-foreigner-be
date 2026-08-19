<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DataTransferObjects\EnrollmentListFilters;
use App\Http\Requests\Enrollment\ListManagerEnrollmentRequestsRequest;
use App\Http\Requests\Enrollment\ShowManagerEnrollmentRequest;
use App\Http\Resources\EnrollmentManagerDetailResource;
use App\Http\Resources\EnrollmentManagerListResource;
use App\Models\EnrollmentRequest;
use App\Services\IdentityReview\EnrollmentReviewQueryService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Illuminate\Http\JsonResponse;

#[Group('Enrollment - Manager')]
final class ManagerEnrollmentController extends BaseController
{
    public function __construct(
        private readonly EnrollmentReviewQueryService $reviewQuery,
    ) {}

    /**
     * List personne physique enrollments (manager, read-only)
     *
     * Default statut: every listable status (including APPROUVEE / REJETEE / ENROLEE).
     * Filters: q, statut (pipe-separated), from, to. Defaults: per_page=20 (max 100).
     */
    public function indexPhysiques(ListManagerEnrollmentRequestsRequest $request): JsonResponse
    {
        return $this->index($request, 'PERSONNE_PHYSIQUE');
    }

    /**
     * List personne morale enrollments (manager, read-only)
     *
     * Default statut: every listable status (including APPROUVEE / REJETEE / ENROLEE).
     * Filters: q, statut (pipe-separated), from, to. Defaults: per_page=20 (max 100).
     */
    public function indexMorales(ListManagerEnrollmentRequestsRequest $request): JsonResponse
    {
        return $this->index($request, 'PERSONNE_MORALE');
    }

    /**
     * Personne physique enrollment detail (manager, read-only)
     *
     * Identity fields + pieces_jointes. No KYC analysis and no instruction actions.
     * 404 when the id is unknown or belongs to a personne morale.
     */
    #[PathParameter('id', description: 'Enrollment request UUID.', type: 'string', format: 'uuid')]
    public function showPhysique(ShowManagerEnrollmentRequest $request): JsonResponse
    {
        return $this->show($request, 'PERSONNE_PHYSIQUE');
    }

    /**
     * Personne morale enrollment detail (manager, read-only)
     *
     * Company fields + pieces_jointes. No KYC analysis and no instruction actions.
     * 404 when the id is unknown or belongs to a personne physique.
     */
    #[PathParameter('id', description: 'Enrollment request UUID.', type: 'string', format: 'uuid')]
    public function showMorale(ShowManagerEnrollmentRequest $request): JsonResponse
    {
        return $this->show($request, 'PERSONNE_MORALE');
    }

    private function index(ListManagerEnrollmentRequestsRequest $request, string $type): JsonResponse
    {
        $this->authorize('supervise', EnrollmentRequest::class);

        $paginator = $this->reviewQuery->list(
            EnrollmentListFilters::forManager($request->validated(), $request->user(), $type)
        );
        $paginator->getCollection()->transform(fn ($item) => new EnrollmentManagerListResource($item));

        return $this->sendResponse('Liste des demandes.', $paginator);
    }

    private function show(ShowManagerEnrollmentRequest $request, string $type): JsonResponse
    {
        $this->authorize('supervise', EnrollmentRequest::class);

        $enrollment = $this->reviewQuery->showForManager((string) $request->validated('id'), $type);
        if ($enrollment === null) {
            return $this->sendError('Demande introuvable.', null, 404);
        }

        $this->authorize('superviseOne', $enrollment);

        return $this->sendResponse('Détail de la demande.', new EnrollmentManagerDetailResource($enrollment));
    }
}
