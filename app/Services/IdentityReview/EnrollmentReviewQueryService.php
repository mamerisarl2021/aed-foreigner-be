<?php

declare(strict_types=1);

namespace App\Services\IdentityReview;

use App\DataTransferObjects\EnrollmentListFilters;
use App\Enums\EnrollmentStatus;
use App\Models\EnrollmentRequest;
use App\Services\Enrollment\EnrollmentSimilarityService;
use App\Services\ServiceResult;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;

class EnrollmentReviewQueryService
{
    public function __construct(
        private readonly EnrollmentSimilarityService $similarityService,
    ) {}

    /**
     * @return LengthAwarePaginator<int, EnrollmentRequest>
     */
    public function list(EnrollmentListFilters $filters): LengthAwarePaginator
    {
        $defaultStatuses = $filters->actor && $filters->actor->hasRole(config('roles.responsable_de_validation'))
            ? EnrollmentStatus::responsableQueue()
            : EnrollmentStatus::agentQueue();

        $statuses = $filters->statuses ?? $defaultStatuses;

        $query = EnrollmentRequest::whereIn('status', $statuses);

        // L'avis de l'agent ayant quitté le statut, c'est par lui que le
        // responsable retrouve « les dossiers proposés au rejet ».
        if ($filters->avis !== null) {
            $query->where('agent_avis', $filters->avis);
        }

        if ($filters->type !== null) {
            $query->where('type', $filters->type);
        }

        if ($filters->q !== null) {
            $q = $filters->q;
            $query->where(function ($uq) use ($q) {
                $uq->where('email', 'like', "%$q%")
                    ->orWhere('phonenumber', 'like', "%$q%")
                    ->orWhereJsonContains('kyc_data->name', $q)
                    ->orWhereJsonContains('kyc_data->first_name', $q)
                    ->orWhereJsonContains('kyc_data->legal_name', $q)
                    ->orWhereJsonContains('kyc_data->registration_number', $q)
                    ->orWhereHas('submittedBy', function ($sub) use ($q) {
                        $sub->where('name', 'like', "%$q%")
                            ->orWhere('first_name', 'like', "%$q%");
                    });
            });
        }

        if ($filters->from !== null) {
            $query->whereDate('created_at', '>=', Carbon::parse($filters->from)->toDateString());
        }
        if ($filters->to !== null) {
            $query->whereDate('created_at', '<=', Carbon::parse($filters->to)->toDateString());
        }

        // UUID PKs are not sequential, so created_at is the meaningful default sort.
        $query->orderBy($filters->orderBy, $filters->orderDir);

        return $query->with(['assignedAgent', 'assignedResponsable', 'submittedBy'])->paginate($filters->perPage);
    }

    public function show(EnrollmentRequest $enrollment): ServiceResult
    {
        $enrollment->loadMissing(['assignedAgent', 'assignedResponsable', 'submittedBy', 'enrolledCompany']);
        $enrollment->setAttribute('similar_enrollments', $this->similarityService->findSimilar($enrollment));

        return ServiceResult::ok('Détail de la demande.', $enrollment);
    }
}
