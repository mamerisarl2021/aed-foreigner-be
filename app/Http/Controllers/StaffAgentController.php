<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Auth\DeleteAgentRequest;
use App\Http\Requests\Auth\ListAgentsRequest;
use App\Http\Requests\Auth\RegisterAgentRequest;
use App\Http\Requests\Auth\ShowAgentRequest;
use App\Http\Requests\Auth\UpdateAgentRequest;
use App\Http\Resources\StaffUserDetailResource;
use App\Http\Resources\StaffUserListResource;
use App\Models\User;
use App\Services\Auth\AdminAuthService;
use App\Services\ServiceResult;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;

#[Group('Admin Auth')]
class StaffAgentController extends BaseController
{
    public function __construct(
        private readonly AdminAuthService $adminAuth,
    ) {}

    /**
     * Register a new staff user (admin only)
     */
    public function register(RegisterAgentRequest $request): JsonResponse
    {
        $this->authorize('manageStaff', User::class);

        $result = $this->adminAuth->registerAgent($request->validated(), $request->user());
        if (! $result->success) {
            return $this->respond($result);
        }

        return $this->sendResponse($result->message, new StaffUserDetailResource($result->data), $result->code);
    }

    /**
     * Update staff user details (admin only)
     */
    #[PathParameter('id', description: 'Staff user UUID.', type: 'string', format: 'uuid')]
    public function update(UpdateAgentRequest $request): JsonResponse
    {
        $this->authorize('manageStaff', User::class);

        $result = $this->adminAuth->updateAgentById(
            (string) $request->validated('id'),
            $request->safe()->except(['id']),
            $request->user(),
        );

        return $this->respondAgent($result);
    }

    /**
     * Delete a staff user (admin only)
     */
    #[PathParameter('id', description: 'Staff user UUID.', type: 'string', format: 'uuid')]
    public function destroy(DeleteAgentRequest $request): JsonResponse
    {
        $this->authorize('manageStaff', User::class);

        return $this->respond($this->adminAuth->deleteAgentById((string) $request->validated('id'), $request->user()));
    }

    /**
     * List staff users (admin only)
     *
     * Optional role filter (`AGENT`, `RESPONSABLE_DE_VALIDATION`, `MANAGER`).
     * Defaults: per_page=15 (max 100), order_by=created_at, order_dir=desc.
     */
    public function index(ListAgentsRequest $request): JsonResponse
    {
        $this->authorize('manageStaff', User::class);

        $result = $this->adminAuth->listAgents($request->validated());
        if (! $result->success) {
            return $this->respond($result);
        }

        /** @var LengthAwarePaginator<int, User> $paginator */
        $paginator = $result->data;
        $paginator->getCollection()->transform(
            fn (User $user) => (new StaffUserListResource($user))->resolve()
        );

        return $this->respondPaginated(ServiceResult::ok($result->message, $this->flattenPagination($paginator)));
    }

    /**
     * Staff user detail (admin only)
     */
    #[PathParameter('id', description: 'Staff user UUID.', type: 'string', format: 'uuid')]
    public function show(ShowAgentRequest $request): JsonResponse
    {
        $this->authorize('manageStaff', User::class);

        return $this->respondAgent($this->adminAuth->showAgent((string) $request->validated('id')));
    }

    private function respondAgent(ServiceResult $result): JsonResponse
    {
        if (! $result->success) {
            return $this->respond($result);
        }

        return $this->sendResponse($result->message, new StaffUserDetailResource($result->data), $result->code);
    }
}
