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
    private const AGENT_ROLES = ['tech_one', 'tech_two', 'tech_three'];

    public function before(User $user, string $ability): ?bool
    {
        if ($user->hasRole('admin')) {
            return true;
        }

        return null;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole([...self::AGENT_ROLES, 'superviseur', 'manager']);
    }

    public function view(User $user, EnrollmentRequest $enrollmentRequest): bool
    {
        return $user->hasAnyRole([...self::AGENT_ROLES, 'superviseur', 'manager']);
    }

    public function claim(User $user, EnrollmentRequest $enrollmentRequest): bool
    {
        return $user->hasAnyRole(self::AGENT_ROLES);
    }

    public function approve(User $user, EnrollmentRequest $enrollmentRequest): bool
    {
        return $user->hasAnyRole(self::AGENT_ROLES)
            && $enrollmentRequest->assigned_agent_id === $user->id;
    }

    public function reject(User $user, EnrollmentRequest $enrollmentRequest): bool
    {
        return $user->hasAnyRole(self::AGENT_ROLES)
            && $enrollmentRequest->assigned_agent_id === $user->id;
    }

    public function requestVisio(User $user, EnrollmentRequest $enrollmentRequest): bool
    {
        return $user->hasAnyRole(self::AGENT_ROLES)
            && $enrollmentRequest->assigned_agent_id === $user->id;
    }

    public function completeVisio(User $user, EnrollmentRequest $enrollmentRequest): bool
    {
        return $user->hasAnyRole(self::AGENT_ROLES)
            && $enrollmentRequest->assigned_agent_id === $user->id;
    }

    public function supervisorApprove(User $user, EnrollmentRequest $enrollmentRequest): bool
    {
        return $user->hasRole('superviseur');
    }

    public function supervisorReject(User $user, EnrollmentRequest $enrollmentRequest): bool
    {
        return $user->hasRole('superviseur')
            && $enrollmentRequest->status === 'REJECTED_BY_AGENT';
    }

    public function supervisorReturn(User $user, EnrollmentRequest $enrollmentRequest): bool
    {
        return $user->hasRole('superviseur')
            && in_array($enrollmentRequest->status, ['APPROVED_BY_AGENT', 'REJECTED_BY_AGENT'], true);
    }

    public function viewEnrollmentStats(User $user): bool
    {
        return $user->hasRole('manager');
    }
}
