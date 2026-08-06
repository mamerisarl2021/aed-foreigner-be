<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\User\LoginWithCodeRequest;
use App\Http\Requests\User\SearchUsersByEmailRequest;
use App\Http\Requests\User\SearchUsersRequest;
use App\Http\Requests\User\SendOtpRequest;
use App\Http\Requests\User\SetClientPasswordRequest;
use App\Http\Requests\User\UpdateUserProfileRequest;
use App\Http\Requests\User\UpdateUserStatusRequest;
use App\Http\Requests\User\VerifyOtpRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Registration\UserRegistrationService;
use App\Services\Users\UserService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('Client Auth')]
class UserController extends BaseController
{
    public function __construct(
        private readonly UserRegistrationService $registration,
        private readonly UserService $users,
    ) {}

    /**
     * Send login OTP to a citizen (NPI lookup via ANIP)
     */
    public function sendOtp(SendOtpRequest $request): JsonResponse
    {
        return $this->respond($this->registration->sendOtp($request->input('npi')));
    }

    /**
     * Verify a citizen login OTP
     */
    public function verifyOtp(VerifyOtpRequest $request): JsonResponse
    {
        return $this->respond($this->registration->verifyOtp(
            $request->input('npi'),
            $request->input('otp'),
        ));
    }

    /**
     * Client login (TrustedX authorization code)
     */
    public function login(LoginWithCodeRequest $request): JsonResponse
    {
        return $this->respond($this->registration->login($request->input('code')));
    }

    /**
     * Client mobile login (TrustedX authorization code)
     */
    public function loginMobile(LoginWithCodeRequest $request): JsonResponse
    {
        return $this->respond($this->registration->loginMobile($request->input('code')));
    }

    /**
     * User detail
     */
    public function show(string $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $this->authorize('view', $user);

        return $this->sendResponse('Utilisateur récupéré.', new UserResource($user));
    }

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
     * Defaults: limit=10 (max 100).
     */
    public function searchPost(SearchUsersByEmailRequest $request): JsonResponse
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
     * Update own profile (email, profile photo)
     */
    public function update(UpdateUserProfileRequest $request, string $id): JsonResponse
    {
        $target = User::findOrFail($id);
        $this->authorize('update', $target);

        $result = $this->registration->updateUser(
            $id,
            ['email' => $request->input('email')],
            $request->file('profile'),
        );

        return $this->respond($result);
    }

    /**
     * Delete a user (admin only)
     */
    public function destroy(string $id): JsonResponse
    {
        $target = User::findOrFail($id);
        $this->authorize('delete', $target);

        return $this->respond($this->users->destroy($id));
    }

    /**
     * Bulk update user statuses (staff)
     */
    public function updateUserStatus(UpdateUserStatusRequest $request): JsonResponse
    {
        $this->authorize('updateStatus', User::class);

        $actorId = is_string($request->user()?->id) ? $request->user()->id : null;

        return $this->respond($this->users->updateStatuses($request->input('users'), $actorId));
    }

    /**
     * Set a client TrustedX password or PIN (admin only)
     */
    public function setPassword(SetClientPasswordRequest $request): JsonResponse
    {
        $this->authorize('setClientPassword', User::class);

        return $this->respond($this->registration->setPassword(
            $request->input('npi'),
            $request->input('password'),
            $request->input('type'),
            $request->user(),
        ));
    }

    /**
     * Send a client reset link by NPI (path parameters)
     */
    public function sendResetLink(string $npi, string $type): JsonResponse
    {
        return $this->respond($this->registration->sendResetLink($npi, $type));
    }
}
