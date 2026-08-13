<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Position d'une demande dans le circuit d'instruction — **jamais** le contenu
 * d'une décision.
 *
 * L'avis de l'agent vit dans `enrollment_requests.agent_avis` ({@see AgentAvis}).
 * Sans cette séparation, un statut comme l'ancien `VALIDATION_AGENT` signifiait
 * à la fois « le dossier est chez le responsable » et « l'agent a approuvé » :
 * le responsable voyait donc une demande « approuvée » avant d'avoir agi.
 */
enum EnrollmentStatus: string
{
    case AwaitingContactVerification = 'AWAITING_CONTACT_VERIFICATION';
    case EnAttenteAgent = 'EN_ATTENTE_AGENT';
    case EnCoursAgent = 'EN_COURS_AGENT';
    case EnAttenteResponsable = 'EN_ATTENTE_RESPONSABLE';
    case EnCoursResponsable = 'EN_COURS_RESPONSABLE';
    case ACorriger = 'A_CORRIGER';
    case Approuvee = 'APPROUVEE';
    case Rejetee = 'REJETEE';
    case Enrolee = 'ENROLEE';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Statuts filtrables sur `GET /enrolements`.
     *
     * `AWAITING_CONTACT_VERIFICATION` en est exclu : la demande morale n'est pas
     * encore entrée dans le circuit d'instruction, personne au backoffice n'a à
     * la traiter.
     *
     * @return list<string>
     */
    public static function listable(): array
    {
        return [
            self::EnAttenteAgent->value,
            self::EnCoursAgent->value,
            self::EnAttenteResponsable->value,
            self::EnCoursResponsable->value,
            self::ACorriger->value,
            self::Approuvee->value,
            self::Rejetee->value,
            self::Enrolee->value,
        ];
    }

    /**
     * File de l'agent de traitement.
     *
     * @return list<string>
     */
    public static function agentQueue(): array
    {
        return [
            self::EnAttenteAgent->value,
            self::EnCoursAgent->value,
        ];
    }

    /**
     * File du responsable de validation.
     *
     * @return list<string>
     */
    public static function responsableQueue(): array
    {
        return [
            self::EnAttenteResponsable->value,
            self::EnCoursResponsable->value,
        ];
    }

    /**
     * Demandes encore en circulation (décompte SLA, doublons, dossier morale ouvert).
     *
     * @return list<string>
     */
    public static function open(): array
    {
        return [...self::agentQueue(), ...self::responsableQueue()];
    }

    /**
     * Statuts dont le verdict est définitif, et donc affichable à tous.
     *
     * @return list<string>
     */
    public static function final(): array
    {
        return [
            self::Approuvee->value,
            self::Rejetee->value,
            self::Enrolee->value,
        ];
    }

    public function isAgentStage(): bool
    {
        return in_array($this->value, self::agentQueue(), true);
    }

    public function isResponsableStage(): bool
    {
        return in_array($this->value, self::responsableQueue(), true);
    }

    public function isFinal(): bool
    {
        return in_array($this->value, self::final(), true);
    }
}
