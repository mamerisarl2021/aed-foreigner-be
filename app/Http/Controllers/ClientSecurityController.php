<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\User\ChangeClientPasswordRequest;
use App\Http\Requests\User\ChangeClientPinRequest;
use App\Http\Requests\User\LogoutClientRequest;
use App\Http\Requests\User\ShowClientSecurityQuestionsRequest;
use App\Http\Requests\User\UpdateClientSecurityQuestionsRequest;
use App\Models\User;
use App\Services\Auth\ClientSecurityService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('Client Auth')]
class ClientSecurityController extends BaseController
{
    public function __construct(
        private readonly ClientSecurityService $security,
    ) {}

    /**
     * Client logout (revokes current Sanctum token)
     */
    public function logout(LogoutClientRequest $request): JsonResponse
    {
        $this->authorize('manageClientSecurity', User::class);

        /** @var User $user */
        $user = $request->user();

        return $this->respond($this->security->logout($user));
    }

    /**
     * Change client TrustedX password (requires current password)
     *
     * Body: current_password, password, password_confirmation (min 8).
     * Revokes all Sanctum tokens; the caller must log in again.
     */
    public function changePassword(ChangeClientPasswordRequest $request): JsonResponse
    {
        $this->authorize('manageClientSecurity', User::class);

        /** @var User $user */
        $user = $request->user();

        return $this->respond($this->security->changePassword(
            $user,
            (string) $request->validated('current_password'),
            (string) $request->validated('password'),
        ));
    }

    /**
     * Change client TrustedX PIN (requires current PIN)
     *
     * Body: current_pin, pin, pin_confirmation — exactly 4 digits.
     */
    public function changePin(ChangeClientPinRequest $request): JsonResponse
    {
        $this->authorize('manageClientSecurity', User::class);

        /** @var User $user */
        $user = $request->user();

        return $this->respond($this->security->changePin(
            $user,
            (string) $request->validated('current_pin'),
            (string) $request->validated('pin'),
        ));
    }

    /**
     * List client security questions (answers omitted)
     */
    public function showSecurityQuestions(ShowClientSecurityQuestionsRequest $request): JsonResponse
    {
        $this->authorize('manageClientSecurity', User::class);

        /** @var User $user */
        $user = $request->user();

        return $this->sendResponse('Questions de sécurité.', $this->security->listSecurityQuestions($user));
    }

    /**
     * Replace client security questions
     *
     * Body: current_password, security_questions (min 2 items with question + answer).
     */
    public function updateSecurityQuestions(UpdateClientSecurityQuestionsRequest $request): JsonResponse
    {
        $this->authorize('manageClientSecurity', User::class);

        /** @var User $user */
        $user = $request->user();

        /** @var list<array{question: string, answer: string}> $questions */
        $questions = $request->validated('security_questions');

        return $this->respond($this->security->updateSecurityQuestions(
            $user,
            (string) $request->validated('current_password'),
            $questions,
        ));
    }
}
