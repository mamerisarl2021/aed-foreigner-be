<?php

declare(strict_types=1);

namespace App\Policies;

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
        if ($enrollmentRequest->status === 'AWAITING_CONTACT_VERIFICATION') {
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

    public function claim(User $user, EnrollmentRequest $enrollmentRequest): bool
    {
        return $user->hasAnyRole(self::AGENT_ROLES)
            && $enrollmentRequest->status !== 'AWAITING_CONTACT_VERIFICATION';
    }

    public function approve(User $user, EnrollmentRequest $enrollmentRequest): bool
    {
        return $user->hasAnyRole(self::AGENT_ROLES)
            && $enrollmentRequest->assigned_agent_id === $user->id
            && $enrollmentRequest->status !== 'AWAITING_CONTACT_VERIFICATION';
    }

    public function reject(User $user, EnrollmentRequest $enrollmentRequest): bool
    {
        return $user->hasAnyRole(self::AGENT_ROLES)
            && $enrollmentRequest->assigned_agent_id === $user->id
            && $enrollmentRequest->status !== 'AWAITING_CONTACT_VERIFICATION';
    }

    public function requestVisio(User $user, EnrollmentRequest $enrollmentRequest): bool
    {
        return $user->hasAnyRole(self::AGENT_ROLES)
            && $enrollmentRequest->assigned_agent_id === $user->id
            && $enrollmentRequest->status !== 'AWAITING_CONTACT_VERIFICATION';
    }

    public function completeVisio(User $user, EnrollmentRequest $enrollmentRequest): bool
    {
        return $user->hasAnyRole(self::AGENT_ROLES)
            && $enrollmentRequest->assigned_agent_id === $user->id
            && $enrollmentRequest->status !== 'AWAITING_CONTACT_VERIFICATION';
    }

    public function supervisorApprove(User $user, EnrollmentRequest $enrollmentRequest): bool
    {
        return $user->hasRole(config('roles.responsable_de_validation'))
            && $enrollmentRequest->status !== 'AWAITING_CONTACT_VERIFICATION';
    }

    public function supervisorReject(User $user, EnrollmentRequest $enrollmentRequest): bool
    {
        return $user->hasRole(config('roles.responsable_de_validation'))
            && $enrollmentRequest->status === 'REJECTED_BY_AGENT';
    }

    public function supervisorReturn(User $user, EnrollmentRequest $enrollmentRequest): bool
    {
        return $user->hasRole(config('roles.responsable_de_validation'))
            && in_array($enrollmentRequest->status, ['APPROVED_BY_AGENT', 'REJECTED_BY_AGENT'], true);
    }

    public function viewEnrollmentStats(User $user): bool
    {
        return $user->hasRole(config('roles.manager'));
    }
}
