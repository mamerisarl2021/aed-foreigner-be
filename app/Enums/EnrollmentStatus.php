<?php

declare(strict_types=1);

namespace App\Enums;

enum EnrollmentStatus: string
{
    case AwaitingContactVerification = 'AWAITING_CONTACT_VERIFICATION';
    case EnAttente = 'EN_ATTENTE';
    case ValidationAgent = 'VALIDATION_AGENT';
    case RejetAgent = 'REJET_AGENT';
    case Approuvee = 'APPROUVEE';
    case Rejetee = 'REJETEE';
    case Enrolee = 'ENROLEE';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** @return list<string> */
    public static function reviewable(): array
    {
        return [
            self::EnAttente->value,
            self::ValidationAgent->value,
            self::RejetAgent->value,
            self::Approuvee->value,
            self::Rejetee->value,
            self::Enrolee->value,
        ];
    }

    public static function fromLegacy(string $status): self
    {
        return match ($status) {
            'PENDING', 'RETURNED_TO_AGENT', 'VISIO_REQUESTED' => self::EnAttente,
            'APPROVED_BY_AGENT' => self::ValidationAgent,
            'REJECTED_BY_AGENT' => self::RejetAgent,
            'APPROVED' => self::Approuvee,
            'REJECTED' => self::Rejetee,
            'FINALIZED' => self::Enrolee,
            'AWAITING_CONTACT_VERIFICATION' => self::AwaitingContactVerification,
            default => self::from($status),
        };
    }
}
