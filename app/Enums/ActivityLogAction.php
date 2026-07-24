<?php

declare(strict_types=1);

namespace App\Enums;

enum ActivityLogAction: string
{
    case DemandeIdentite = 'DEMANDE_IDENTITE';
    case DemandeIdentiteMorale = 'DEMANDE_IDENTITE_MORALE';
    case ValidationAgent = 'VALIDATION_AGENT';
    case RejetAgent = 'REJET_AGENT';
    case ValidationResponsable = 'VALIDATION_RESPONSABLE';
    case RejetConfirme = 'REJET_CONFIRME';
    case RetourAgent = 'RETOUR_AGENT';
    case EnrolementFinalise = 'ENROLEMENT_FINALISE';
    case UtilisateurCree = 'UTILISATEUR_CREE';

    public function label(): string
    {
        return match ($this) {
            self::DemandeIdentite => 'DEMANDE D\'IDENTITE',
            self::DemandeIdentiteMorale => 'DEMANDE D\'IDENTITE MORALE',
            self::ValidationAgent => 'VALIDATION AGENT',
            self::RejetAgent => 'REJET AGENT',
            self::ValidationResponsable => 'VALIDATION RESPONSABLE',
            self::RejetConfirme => 'REJET CONFIRME',
            self::RetourAgent => 'RETOUR AGENT',
            self::EnrolementFinalise => 'ENROLEMENT FINALISE',
            self::UtilisateurCree => 'UTILISATEUR CREE',
        };
    }
}
