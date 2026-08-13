<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\EnrollmentStatus;
use App\Models\EnrollmentRequest;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class EnrollmentRequestPolicy
{
    use HandlesAuthorization;

    /** @var list<string> */
    private const AGENT_ROLES = ['agent'];

    public function before(User $user, string $ability): ?bool
    {
        if ($user->hasRole(config('roles.administrateur_plateforme'))) {
            return true;
        }

        return null;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole([...self::AGENT_ROLES, config('roles.responsable_de_validation'), config('roles.manager')]);
    }

    public function view(User $user, EnrollmentRequest $enrollmentRequest): bool
    {
        if ($enrollmentRequest->status === EnrollmentStatus::AwaitingContactVerification) {
            return $this->viewOwnMorale($user, $enrollmentRequest);
        }

        return $user->hasAnyRole([...self::AGENT_ROLES, config('roles.responsable_de_validation'), config('roles.manager')]);
    }

    public function submitMorale(User $user): bool
    {
        return $user->hasRole(config('roles.client'))
            && $user->status === 'ACTIVE'
            && $user->identities()
                ->where('type', 'IN_PERSON')
                ->where('status', 'APPROVED')
                ->exists();
    }

    public function viewOwnMorale(User $user, EnrollmentRequest $enrollmentRequest): bool
    {
        return $enrollmentRequest->isPersonneMorale()
            && $enrollmentRequest->submitted_by_user_id === $user->id;
    }

    public function listOwnMorale(User $user): bool
    {
        return $user->hasRole(config('roles.client'));
    }

    public function correctMorale(User $user, EnrollmentRequest $enrollmentRequest): bool
    {
        $deadlineOk = $enrollmentRequest->correction_deadline_at === null
            || ! $enrollmentRequest->correction_deadline_at->isPast();

        return $this->viewOwnMorale($user, $enrollmentRequest)
            && $enrollmentRequest->status === EnrollmentStatus::ACorriger
            && $deadlineOk;
    }

    public function instruction(User $user, EnrollmentRequest $enrollmentRequest): bool
    {
        if (! $user->hasAnyRole(self::AGENT_ROLES)) {
            return false;
        }

        // L'instruction suppose une prise en charge : c'est elle qui fait passer
        // la demande en EN_COURS_AGENT.
        if ($enrollmentRequest->status !== EnrollmentStatus::EnCoursAgent) {
            return false;
        }

        return $enrollmentRequest->assigned_agent_id === $user->id;
    }

    public function claim(User $user, EnrollmentRequest $enrollmentRequest): bool
    {
        if (! $user->hasAnyRole(self::AGENT_ROLES)) {
            return false;
        }

        return $enrollmentRequest->status === EnrollmentStatus::EnAttenteAgent
            && $enrollmentRequest->assigned_agent_id === null;
    }

    public function claimValidation(User $user, EnrollmentRequest $enrollmentRequest): bool
    {
        if (! $user->hasRole(config('roles.responsable_de_validation'))) {
            return false;
        }

        return $enrollmentRequest->status === EnrollmentStatus::EnAttenteResponsable
            && $enrollmentRequest->assigned_responsable_id === null;
    }

    public function validation(User $user, EnrollmentRequest $enrollmentRequest): bool
    {
        if (! $user->hasRole(config('roles.responsable_de_validation'))) {
            return false;
        }

        if ($enrollmentRequest->status !== EnrollmentStatus::EnCoursResponsable) {
            return false;
        }

        return (string) $enrollmentRequest->assigned_responsable_id === (string) $user->id;
    }

    public function viewEnrollmentStats(User $user): bool
    {
        return $user->hasRole(config('roles.manager'));
    }
}
