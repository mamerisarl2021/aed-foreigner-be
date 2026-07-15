<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\EnrollmentRejectMotif;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class EnrollmentRejectMotifPolicy
{
    use HandlesAuthorization;

    /** @var list<string> */
    private const REVIEWER_ROLES = ['tech_one', 'tech_two', 'tech_three', 'superviseur', 'admin'];

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(self::REVIEWER_ROLES);
    }

    public function view(User $user, EnrollmentRejectMotif $motif): bool
    {
        return $user->hasAnyRole(self::REVIEWER_ROLES);
    }
}
