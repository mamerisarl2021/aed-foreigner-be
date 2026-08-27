<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Admin\ListEnrolledCompaniesRequest;
use App\Http\Requests\Admin\ShowEnrolledCompanyRequest;
use App\Http\Resources\EnrolledCompanyDetailResource;
use App\Http\Resources\EnrolledCompanyListResource;
use App\Services\Admin\EnrolledCompanyService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Illuminate\Http\JsonResponse;

#[Group('Admin')]
final class AdminEnrolledCompanyController extends BaseController
{
    public function __construct(
        private readonly EnrolledCompanyService $enrolledCompanyService,
    ) {}

    /**
     * List enrolled companies (read-only)
     *
     * Personne morale counterpart of GET /admin/enrolled-persons, which is
     * physique-only. Reads `enrolled_companies` — the record created when a
     * company enrolment is approved — and never the requests themselves.
     * `identifiant` is the company's unique id, the NPI's counterpart.
     * Defaults: per_page=15 (max 100), order_by=enrolled_at, order_dir=desc.
     */
    public function index(ListEnrolledCompaniesRequest $request): JsonResponse
    {
        $this->authorize('viewAnyEnrolledCompany');

        $paginator = $this->enrolledCompanyService->list($request);
        $paginator->getCollection()->transform(fn ($item) => new EnrolledCompanyListResource($item));

        return $this->sendResponse('Liste des entreprises enrôlées.', $paginator);
    }

    /**
     * Enrolled company detail (read-only)
     *
     * Company fields only: no KYC analysis — a personne morale never goes
     * through liveness. 404 when the id is unknown or the company is inactive.
     */
    #[PathParameter('id', description: 'Enrolled company UUID.', type: 'string', format: 'uuid')]
    public function show(ShowEnrolledCompanyRequest $request): JsonResponse
    {
        $this->authorize('viewEnrolledCompany');

        $result = $this->enrolledCompanyService->show((string) $request->validated('id'));
        if (! $result->success) {
            return $this->respond($result);
        }

        return $this->sendResponse($result->message, new EnrolledCompanyDetailResource($result->data));
    }
}
