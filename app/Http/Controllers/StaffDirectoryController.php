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
            (string) $request->input('query'),
            (int) $request->input('limit', 10),
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
            (string) $request->input('email'),
            (int) $request->input('limit', 10),
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

        return $this->respond($this->users->updateStatuses($request->input('users'), $actorId));
    }

    /**
     * Set a client TrustedX password or PIN (admin only)
     */
    public function setClientPassword(SetClientPasswordRequest $request): JsonResponse
    {
        $this->authorize('setClientPassword', User::class);

        return $this->respond($this->registration->setPassword(
            $request->input('npi'),
            $request->input('password'),
            $request->input('type'),
            $request->user(),
        ));
    }
}
