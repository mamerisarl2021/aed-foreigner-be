<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\EnrolledPersonDetailResource;
use App\Http\Resources\EnrolledPersonListResource;
use App\Services\Admin\EnrolledPersonService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use OpenApi\Annotations as OA;

final class AdminEnrolledPersonController extends BaseController
{
    public function __construct(
        private readonly EnrolledPersonService $enrolledPersonService,
    ) {}

    /**
     * @OA\Get(
     *      path="/api/v1/admin/enrolled-persons",
     *      operationId="adminEnrolledPersons",
     *      tags={"Admin"},
     *      summary="List enrolled persons (read-only)",
     *      security={{"sanctum":{}}},
     *
     *      @OA\Parameter(name="q", in="query", @OA\Schema(type="string")),
     *      @OA\Parameter(name="per_page", in="query", @OA\Schema(type="integer", default=15)),
     *
     *      @OA\Response(response=200, description="Paginated enrolled persons")
     * )
     */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAnyEnrolledPerson');

        $paginator = $this->enrolledPersonService->list($request);
        $paginator->getCollection()->transform(fn ($item) => new EnrolledPersonListResource($item));

        return $this->sendResponse('Liste des personnes enrôlées.', $paginator);
    }

    /**
     * @OA\Get(
     *      path="/api/v1/admin/enrolled-persons/{id}",
     *      operationId="adminEnrolledPersonShow",
     *      tags={"Admin"},
     *      summary="Enrolled person detail (read-only)",
     *      security={{"sanctum":{}}},
     *
     *      @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *
     *      @OA\Response(response=200, description="Detail"),
     *      @OA\Response(response=404, description="Not found")
     * )
     */
    public function show(string $id): JsonResponse
    {
        Gate::authorize('viewEnrolledPerson');

        $result = $this->enrolledPersonService->show($id);
        if (! $result->success) {
            return $this->respond($result);
        }

        return $this->sendResponse($result->message, new EnrolledPersonDetailResource($result->data));
    }
}
