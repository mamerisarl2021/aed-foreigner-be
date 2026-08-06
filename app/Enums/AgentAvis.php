<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Avis rendu par l'agent de traitement à l'issue de l'instruction.
 *
 * C'est une **proposition** au responsable de validation, jamais une décision
 * finale : elle est portée par une colonne dédiée pour qu'aucun statut de
 * demande ne puisse la faire passer pour un verdict à un autre niveau.
 */
enum AgentAvis: string
{
    case Favorable = 'FAVORABLE';
    case Defavorable = 'DEFAVORABLE';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::Favorable => 'Favorable',
            self::Defavorable => 'Défavorable',
        };
    }
}
