<?php

namespace App\Policies;

use App\Models\EnrollmentRequest;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class EnrollmentRequestPolicy
{
    use HandlesAuthorization;

    public function before(User $user, $ability)
    {
        if ($user->hasRole('admin')) {
            return true;
        }
    }

    public function viewAny(User $user)
    {
        return $user->hasRole(['agent', 'superviseur']);
    }

    public function view(User $user, EnrollmentRequest $enrollmentRequest)
    {
        return $user->hasRole(['agent', 'superviseur']);
    }

    public function claim(User $user, EnrollmentRequest $enrollmentRequest)
    {
        return $user->hasRole('agent');
    }

    public function approve(User $user, EnrollmentRequest $enrollmentRequest)
    {
        return $user->hasRole('agent') && $enrollmentRequest->assigned_agent_id === $user->id;
    }

    public function reject(User $user, EnrollmentRequest $enrollmentRequest)
    {
        return $user->hasRole('agent') && $enrollmentRequest->assigned_agent_id === $user->id;
    }

    public function supervisorApprove(User $user, EnrollmentRequest $enrollmentRequest)
    {
        return $user->hasRole('superviseur');
    }

    public function supervisorReject(User $user, EnrollmentRequest $enrollmentRequest)
    {
        return $user->hasRole('superviseur');
    }
}
