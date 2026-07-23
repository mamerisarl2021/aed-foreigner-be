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
        if ($enrollmentRequest->status === EnrollmentStatus::AwaitingContactVerification->value) {
            return $this->viewOwnMorale($user, $enrollmentRequest);
        }

        return $user->hasAnyRole([...self::AGENT_ROLES, config('roles.responsable_de_validation'), config('roles.manager')]);
    }

    public function submitMorale(User $user): bool
    {
        return $user->hasRole(config('roles.client')) && $user->status === 'ACTIVE';
    }

    public function viewOwnMorale(User $user, EnrollmentRequest $enrollmentRequest): bool
    {
        return $enrollmentRequest->isPersonneMorale()
            && $enrollmentRequest->submitted_by_user_id === $user->id;
    }

    public function instruction(User $user, EnrollmentRequest $enrollmentRequest): bool
    {
        return $user->hasAnyRole(self::AGENT_ROLES)
            && $enrollmentRequest->status === EnrollmentStatus::EnAttente->value;
    }

    public function validation(User $user, EnrollmentRequest $enrollmentRequest): bool
    {
        if (! $user->hasRole(config('roles.responsable_de_validation'))) {
            return false;
        }

        return in_array($enrollmentRequest->status, [
            EnrollmentStatus::ValidationAgent->value,
            EnrollmentStatus::RejetAgent->value,
        ], true);
    }

    public function viewEnrollmentStats(User $user): bool
    {
        return $user->hasRole(config('roles.manager'));
    }
}
