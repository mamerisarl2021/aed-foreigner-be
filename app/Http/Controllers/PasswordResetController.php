<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\PasswordReset\ResetClientCredentialsRequest;
use App\Http\Requests\PasswordReset\ResetClientPasswordRequest;
use App\Http\Requests\PasswordReset\SendClientResetLinkRequest;
use App\Services\PasswordReset\ClientPasswordResetService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('Client Auth')]
class PasswordResetController extends BaseController
{
    public function __construct(
        private readonly ClientPasswordResetService $passwordReset,
    ) {}

    /**
     * Send client password/PIN reset link
     *
     * Legacy citizen flow (NPI + TrustedX). Emails a one-time reset link to the account owner.
     */
    public function sendResetLink(SendClientResetLinkRequest $request): JsonResponse
    {
        return $this->respond($this->passwordReset->sendResetLink(
            (string) $request->validated('npi'),
            (string) $request->validated('type'),
        ));
    }

    /**
     * Reset client password or PIN using a reset token
     */
    public function resetPassword(ResetClientPasswordRequest $request): JsonResponse
    {
        return $this->respond($this->passwordReset->resetPassword(
            (string) $request->validated('token'),
            (string) $request->validated('password'),
            (string) $request->validated('npi'),
            (string) $request->validated('type'),
        ));
    }

    /**
     * Reset client password and/or PIN in one call
     */
    public function resetSome(ResetClientCredentialsRequest $request): JsonResponse
    {
        $password = $request->validated('password');
        $pin = $request->validated('pin');

        return $this->respond($this->passwordReset->resetCredentials(
            (string) $request->validated('token'),
            (string) $request->validated('npi'),
            is_string($password) ? $password : null,
            is_string($pin) ? $pin : null,
        ));
    }
}
