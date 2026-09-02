<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * Entreprises enrôlées — réservées à l'administrateur plateforme, comme les
 * personnes enrôlées ({@see EnrolledPersonPolicy}).
 *
 * Volontairement séparé de EnrollmentRequestPolicy : on ne lit plus ici une
 * demande d'enrôlement mais son aboutissement, une entreprise inscrite. Les
 * rôles d'instruction n'ont rien à y faire — la supervision leur montre déjà
 * les dossiers via `/management/enrolements/morales`.
 */
class EnrolledCompanyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(config('roles.administrateur_plateforme'));
    }

    public function view(User $user): bool
    {
        return $user->hasRole(config('roles.administrateur_plateforme'));
    }

    public function updateStatus(User $user): bool
    {
        return $user->hasRole(config('roles.administrateur_plateforme'));
    }
}
