<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Admin\ListEnrolledPersonsRequest;
use App\Http\Requests\Admin\ShowEnrolledPersonRequest;
use App\Http\Resources\EnrolledPersonDetailResource;
use App\Http\Resources\EnrolledPersonListResource;
use App\Services\Admin\EnrolledPersonService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

#[Group('Admin')]
final class AdminEnrolledPersonController extends BaseController
{
    public function __construct(
        private readonly EnrolledPersonService $enrolledPersonService,
    ) {}

    /**
     * List enrolled persons (read-only)
     *
     * Defaults: per_page=15 (max 100), order_by=enrolled_at, order_dir=desc.
     */
    public function index(ListEnrolledPersonsRequest $request): JsonResponse
    {
        Gate::authorize('viewAnyEnrolledPerson');

        $paginator = $this->enrolledPersonService->list($request);
        $paginator->getCollection()->transform(fn ($item) => new EnrolledPersonListResource($item));

        return $this->sendResponse('Liste des personnes enrôlées.', $paginator);
    }

    /**
     * Enrolled person detail (read-only)
     */
    #[PathParameter('id', description: 'Enrolled person (user) UUID.', type: 'string', format: 'uuid')]
    public function show(ShowEnrolledPersonRequest $request): JsonResponse
    {
        Gate::authorize('viewEnrolledPerson');

        $result = $this->enrolledPersonService->show((string) $request->validated('id'));
        if (! $result->success) {
            return $this->respond($result);
        }

        return $this->sendResponse($result->message, new EnrolledPersonDetailResource($result->data));
    }
}
