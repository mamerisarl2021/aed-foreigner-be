<?php

declare(strict_types=1);

namespace App\Enums;

enum ActivityLogAction: string
{
    case DemandeIdentite = 'DEMANDE_IDENTITE';
    case DemandeIdentiteMorale = 'DEMANDE_IDENTITE_MORALE';
    case PriseEnChargeAgent = 'PRISE_EN_CHARGE_AGENT';
    case PriseEnChargeResponsable = 'PRISE_EN_CHARGE_RESPONSABLE';
    case ValidationAgent = 'VALIDATION_AGENT';
    case RejetAgent = 'REJET_AGENT';
    case ValidationResponsable = 'VALIDATION_RESPONSABLE';
    case RejetConfirme = 'REJET_CONFIRME';
    case RetourAgent = 'RETOUR_AGENT';
    case EnrolementFinalise = 'ENROLEMENT_FINALISE';
    case OtpEnvoye = 'OTP_ENVOYE';
    case OtpVerifie = 'OTP_VERIFIE';
    case KycVerifie = 'KYC_VERIFIE';
    case DocumentLu = 'DOCUMENT_LU';
    case UtilisateurCree = 'UTILISATEUR_CREE';
    case UtilisateurModifie = 'UTILISATEUR_MODIFIE';
    case UtilisateurSupprime = 'UTILISATEUR_SUPPRIME';
    case StatutUtilisateurModifie = 'STATUT_UTILISATEUR_MODIFIE';
    case MotifCree = 'MOTIF_CREE';
    case MotifModifie = 'MOTIF_MODIFIE';
    case MotifSupprime = 'MOTIF_SUPPRIME';
    case ConnexionAdmin = 'CONNEXION_ADMIN';
    case DeconnexionAdmin = 'DECONNEXION_ADMIN';
    case MotDePasseChange = 'MOT_DE_PASSE_CHANGE';
    case MotDePasseReinitialise = 'MOT_DE_PASSE_REINITIALISE';
    case ConnexionClient = 'CONNEXION_CLIENT';
    case DocumentDechiffre = 'DOCUMENT_DECHIFFRE';

    public function label(): string
    {
        return match ($this) {
            self::DemandeIdentite => 'DEMANDE D\'IDENTITE',
            self::DemandeIdentiteMorale => 'DEMANDE D\'IDENTITE MORALE',
            self::PriseEnChargeAgent => 'PRISE EN CHARGE AGENT',
            self::PriseEnChargeResponsable => 'PRISE EN CHARGE RESPONSABLE',
            self::ValidationAgent => 'VALIDATION AGENT',
            self::RejetAgent => 'REJET AGENT',
            self::ValidationResponsable => 'VALIDATION RESPONSABLE',
            self::RejetConfirme => 'REJET CONFIRME',
            self::RetourAgent => 'RETOUR AGENT',
            self::EnrolementFinalise => 'ENROLEMENT FINALISE',
            self::OtpEnvoye => 'OTP ENVOYE',
            self::OtpVerifie => 'OTP VERIFIE',
            self::KycVerifie => 'KYC VERIFIE',
            self::DocumentLu => 'DOCUMENT LU',
            self::UtilisateurCree => 'UTILISATEUR CREE',
            self::UtilisateurModifie => 'UTILISATEUR MODIFIE',
            self::UtilisateurSupprime => 'UTILISATEUR SUPPRIME',
            self::StatutUtilisateurModifie => 'STATUT UTILISATEUR MODIFIE',
            self::MotifCree => 'MOTIF CREE',
            self::MotifModifie => 'MOTIF MODIFIE',
            self::MotifSupprime => 'MOTIF SUPPRIME',
            self::ConnexionAdmin => 'CONNEXION ADMIN',
            self::DeconnexionAdmin => 'DECONNEXION ADMIN',
            self::MotDePasseChange => 'MOT DE PASSE CHANGE',
            self::MotDePasseReinitialise => 'MOT DE PASSE REINITIALISE',
            self::ConnexionClient => 'CONNEXION CLIENT',
            self::DocumentDechiffre => 'DOCUMENT DECHIFFRE',
        };
    }
}
