<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\User\ClientLoginRequest;
use App\Http\Requests\User\LoginWithCodeRequest;
use App\Http\Requests\User\SendOtpRequest;
use App\Http\Requests\User\UpdateUserProfileRequest;
use App\Http\Requests\User\VerifyOtpRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Registration\UserRegistrationService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Illuminate\Http\JsonResponse;

#[Group('Client Auth')]
class UserController extends BaseController
{
    public function __construct(
        private readonly UserRegistrationService $registration,
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
    public function login(ClientLoginRequest $request): JsonResponse
    {
        return $this->respond($this->registration->login(
            (string) $request->validated('code'),
            (string) $request->validated('redirect_uri'),
        ));
    }

    /**
     * Client mobile login (TrustedX authorization code)
     */
    public function loginMobile(LoginWithCodeRequest $request): JsonResponse
    {
        return $this->respond($this->registration->loginMobile($request->input('code')));
    }

    /**
     * Update own profile (email, profile photo)
     */
    #[PathParameter('id', description: 'User UUID.', type: 'string', format: 'uuid')]
    public function update(UpdateUserProfileRequest $request): JsonResponse
    {
        $id = (string) $request->validated('id');
        $target = User::query()->findOrFail($id);
        $this->authorize('update', $target);

        $result = $this->registration->updateUser(
            $id,
            ['email' => $request->input('email')],
            $request->file('profile'),
        );

        if (! $result->success) {
            return $this->respond($result);
        }

        if ($result->data instanceof User) {
            return $this->sendResponse($result->message, new UserResource($result->data), $result->code);
        }

        return $this->respond($result);
    }
}
