<?php

declare(strict_types=1);

namespace App\Enums;

enum ActivityLogAction: string
{
    case DemandeIdentite = 'DEMANDE_IDENTITE';
    case DemandeIdentiteMorale = 'DEMANDE_IDENTITE_MORALE';
    case PriseEnChargeAgent = 'PRISE_EN_CHARGE_AGENT';
    case PriseEnChargeResponsable = 'PRISE_EN_CHARGE_RESPONSABLE';
    /** Historical event code — not an enrollment_requests.status value. */
    case ValidationAgent = 'VALIDATION_AGENT';
    /** Historical event code — not an enrollment_requests.status value. */
    case RejetAgent = 'REJET_AGENT';
    case ValidationResponsable = 'VALIDATION_RESPONSABLE';
    case RejetConfirme = 'REJET_CONFIRME';
    case RetourAgent = 'RETOUR_AGENT';
    case CorrectionMorale = 'CORRECTION_MORALE';
    case CorrectionMoraleExpiree = 'CORRECTION_MORALE_EXPIREE';
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
    case DeconnexionClient = 'DECONNEXION_CLIENT';
    case DocumentDechiffre = 'DOCUMENT_DECHIFFRE';
    case PsceqConsultation = 'PSCEQ_CONSULTATION';
    case PsceqClientCree = 'PSCEQ_CLIENT_CREE';
    case PsceqClientModifie = 'PSCEQ_CLIENT_MODIFIE';
    case PsceqClientSupprime = 'PSCEQ_CLIENT_SUPPRIME';
    case PsceqClientRegenere = 'PSCEQ_CLIENT_REGENERE';
    case PsceqClientRevoque = 'PSCEQ_CLIENT_REVOQUE';
    case PsceqClientReactive = 'PSCEQ_CLIENT_REACTIVE';
    case EntrepriseStatutModifie = 'ENTREPRISE_STATUT_MODIFIE';

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
            self::CorrectionMorale => 'CORRECTION MORALE',
            self::CorrectionMoraleExpiree => 'CORRECTION MORALE EXPIREE',
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
            self::DeconnexionClient => 'DECONNEXION CLIENT',
            self::DocumentDechiffre => 'DOCUMENT DECHIFFRE',
            self::PsceqConsultation => 'CONSULTATION PSCEQ',
            self::PsceqClientCree => 'PSCEQ CLIENT CREE',
            self::PsceqClientModifie => 'PSCEQ CLIENT MODIFIE',
            self::PsceqClientSupprime => 'PSCEQ CLIENT SUPPRIME',
            self::PsceqClientRegenere => 'PSCEQ CLIENT REGENERE',
            self::PsceqClientRevoque => 'PSCEQ CLIENT REVOQUE',
            self::PsceqClientReactive => 'PSCEQ CLIENT REACTIVE',
            self::EntrepriseStatutModifie => 'ENTREPRISE STATUT MODIFIE',
        };
    }
}
