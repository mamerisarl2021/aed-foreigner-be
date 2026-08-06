<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\EnrollmentStatus;
use App\Models\User;

/**
 * Libellé d'un statut de demande **selon celui qui le lit**.
 *
 * Règle appliquée ici : un niveau ne voit un verdict que s'il est le sien ou
 * s'il est définitif pour tout le dossier. Tant que la demande est chez
 * quelqu'un d'autre, le libellé décrit où elle est, jamais ce qui a été décidé
 * ailleurs — c'est ce qui empêche un responsable de lire « approuvée » sur un
 * dossier qu'il n'a pas encore instruit.
 */
final class EnrollmentStatusPresenter
{
    public const PERSPECTIVE_AGENT = 'AGENT';

    public const PERSPECTIVE_RESPONSABLE = 'RESPONSABLE';

    public const PERSPECTIVE_SUPERVISION = 'SUPERVISION';

    public const PERSPECTIVE_DEMANDEUR = 'DEMANDEUR';

    /**
     * Le rôle décide du vocabulaire, pas de l'état : la même demande garde un
     * seul `status` machine, seul son libellé change.
     */
    public static function perspective(?User $user): string
    {
        if (! $user) {
            return self::PERSPECTIVE_DEMANDEUR;
        }

        return match (true) {
            $user->hasRole(config('roles.responsable_de_validation')) => self::PERSPECTIVE_RESPONSABLE,
            $user->hasRole(config('roles.agent')) => self::PERSPECTIVE_AGENT,
            $user->hasAnyRole([
                config('roles.manager'),
                config('roles.administrateur_plateforme'),
            ]) => self::PERSPECTIVE_SUPERVISION,
            default => self::PERSPECTIVE_DEMANDEUR,
        };
    }

    public static function labelFor(EnrollmentStatus $status, ?User $user): string
    {
        return self::label($status, self::perspective($user));
    }

    public static function label(EnrollmentStatus $status, string $perspective): string
    {
        if ($status->isFinal()) {
            return match ($status) {
                EnrollmentStatus::Approuvee => 'Approuvée',
                EnrollmentStatus::Rejetee => 'Rejetée',
                default => 'Enrôlée',
            };
        }

        return match ($perspective) {
            self::PERSPECTIVE_AGENT => self::agentLabel($status),
            self::PERSPECTIVE_RESPONSABLE => self::responsableLabel($status),
            self::PERSPECTIVE_SUPERVISION => self::supervisionLabel($status),
            default => self::demandeurLabel($status),
        };
    }

    private static function agentLabel(EnrollmentStatus $status): string
    {
        return match ($status) {
            EnrollmentStatus::AwaitingContactVerification => 'Vérification des contacts',
            EnrollmentStatus::EnAttenteAgent => 'À traiter',
            EnrollmentStatus::EnCoursAgent => 'En cours d\'instruction',
            // Une fois l'avis rendu, la demande n'est plus au niveau de l'agent :
            // son sort dépend du responsable, donc aucun verdict ici.
            default => 'Transmise au responsable',
        };
    }

    private static function responsableLabel(EnrollmentStatus $status): string
    {
        return match ($status) {
            EnrollmentStatus::AwaitingContactVerification => 'Vérification des contacts',
            EnrollmentStatus::EnAttenteAgent, EnrollmentStatus::EnCoursAgent => 'En instruction',
            // Rien n'a encore été décidé à ce niveau tant que le responsable n'a
            // pas pris la décision en charge, puis tranché.
            EnrollmentStatus::EnAttenteResponsable => 'À valider',
            default => 'En cours de validation',
        };
    }

    private static function supervisionLabel(EnrollmentStatus $status): string
    {
        return match ($status) {
            EnrollmentStatus::AwaitingContactVerification => 'Vérification des contacts',
            EnrollmentStatus::EnAttenteAgent => 'En attente d\'un agent',
            EnrollmentStatus::EnCoursAgent => 'En cours d\'instruction',
            EnrollmentStatus::EnAttenteResponsable => 'En attente du responsable',
            default => 'En cours de validation',
        };
    }

    private static function demandeurLabel(EnrollmentStatus $status): string
    {
        return $status === EnrollmentStatus::AwaitingContactVerification
            ? 'Vérification de vos contacts'
            : 'En cours de traitement';
    }
}
