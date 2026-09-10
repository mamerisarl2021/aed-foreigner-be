<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\User\SearchUsersByEmailRequest;
use App\Http\Requests\User\SearchUsersRequest;
use App\Http\Requests\User\SetClientPasswordRequest;
use App\Http\Requests\User\UpdateUserStatusRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Registration\UserRegistrationService;
use App\Services\Users\UserService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('Admin')]
class StaffDirectoryController extends BaseController
{
    public function __construct(
        private readonly UserRegistrationService $registration,
        private readonly UserService $users,
    ) {}

    /**
     * Search users by email, name or NPI (staff only)
     *
     * Defaults: limit=10 (max 100).
     */
    public function search(SearchUsersRequest $request): JsonResponse
    {
        $this->authorize('search', User::class);

        $result = $this->users->search(
            (string) $request->validated('query'),
            (int) ($request->validated('limit') ?? 10),
            (string) $request->user()?->id,
        );

        if (! $result->success) {
            return $this->respond($result);
        }

        return $this->sendResponse($result->message, UserResource::collection($result->data));
    }

    /**
     * Search users by email (staff only)
     *
     * Defaults: limit=10 (max 100). Historical path: POST /users-email/search.
     */
    public function searchByEmail(SearchUsersByEmailRequest $request): JsonResponse
    {
        $this->authorize('search', User::class);

        $result = $this->users->searchByEmail(
            (string) $request->validated('email'),
            (int) ($request->validated('limit') ?? 10),
            (string) $request->user()?->id,
        );

        if (! $result->success) {
            return $this->respond($result);
        }

        return $this->sendResponse($result->message, UserResource::collection($result->data));
    }

    /**
     * Bulk update user statuses (staff)
     */
    public function updateStatus(UpdateUserStatusRequest $request): JsonResponse
    {
        $this->authorize('updateStatus', User::class);

        $actorId = is_string($request->user()?->id) ? $request->user()->id : null;
        $users = $this->validatedStatusRows($request);
        if ($users === []) {
            return $this->sendError('Données invalides.', null, 422);
        }

        return $this->respond($this->users->updateStatuses($users, $actorId));
    }

    /**
     * Set a client TrustedX password or PIN (admin only)
     */
    public function setClientPassword(SetClientPasswordRequest $request): JsonResponse
    {
        $this->authorize('setClientPassword', User::class);

        return $this->respond($this->registration->setPassword(
            (string) $request->validated('npi'),
            (string) $request->validated('password'),
            (string) $request->validated('type'),
            $request->user(),
        ));
    }

    /**
     * @return list<array{id: string, status: string}>
     */
    private function validatedStatusRows(UpdateUserStatusRequest $request): array
    {
        $payload = $request->validated('users');
        if (! is_array($payload)) {
            return [];
        }

        $rows = [];
        foreach ($payload as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = $row['id'] ?? null;
            $status = $row['status'] ?? null;
            if (is_string($id) && is_string($status)) {
                $rows[] = ['id' => $id, 'status' => $status];
            }
        }

        return $rows;
    }
}
