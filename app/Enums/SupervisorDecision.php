<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Décision du responsable de validation (PATCH .../validation).
 */
enum SupervisorDecision: string
{
    case Approuvee = 'APPROUVEE';
    case RejetConfirme = 'REJET_CONFIRME';
    case RetourAgent = 'RETOUR_AGENT';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
