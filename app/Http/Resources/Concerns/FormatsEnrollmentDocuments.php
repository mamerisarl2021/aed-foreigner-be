<?php

declare(strict_types=1);

namespace App\Http\Resources\Concerns;

use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

trait FormatsEnrollmentDocuments
{
    /**
     * @param  array<string, mixed>|null  $docs
     */
    protected function documentUrl(?array $docs, string $key): ?string
    {
        if (! is_array($docs) || empty($docs[$key])) {
            return null;
        }

        return Storage::cloud()->temporaryUrl($docs[$key], Carbon::now()->addDays(3));
    }

    /**
     * @param  array<string, mixed>|null  $docs
     * @return list<array{type: string, label: string, url: string}>
     */
    protected function physiquePiecesJointes(?array $docs): array
    {
        $pieces = [];
        $map = [
            'recto' => 'Pièce d\'identité (recto)',
            'verso' => 'Pièce d\'identité (verso)',
            'selfie' => 'Selfie',
            'profile' => 'Photo de profil',
        ];

        foreach ($map as $key => $label) {
            $url = $this->documentUrl($docs, $key);
            if ($url) {
                $pieces[] = ['type' => $key, 'label' => $label, 'url' => $url];
            }
        }

        return $pieces;
    }

    /**
     * @param  array<string, mixed>|null  $docs
     * @return list<array{type: string, label: string, url: string}>
     */
    protected function moralePiecesJointes(?array $docs): array
    {
        $pieces = [];
        $map = [
            'trade_register_extract' => 'Extrait du registre de commerce',
            'statutes' => 'Statuts',
            'procuration' => 'Procuration',
        ];

        foreach ($map as $key => $label) {
            $url = $this->documentUrl($docs, $key);
            if ($url) {
                $pieces[] = ['type' => $key, 'label' => $label, 'url' => $url];
            }
        }

        return $pieces;
    }

    /**
     * @return array{nom: ?string, prenom: ?string}|null
     */
    protected function formatAgent(?object $agent): ?array
    {
        if (! $agent) {
            return null;
        }

        return [
            'nom' => $agent->name ?? null,
            'prenom' => $agent->first_name ?? null,
        ];
    }

    /**
     * Nom du demandeur pour une ligne de liste : le KYC pour une personne
     * physique, le compte qui a déposé le dossier pour une personne morale.
     *
     * Reçoit ses données plutôt que de lire le modèle, comme `formatAgent` :
     * les deux ressources de liste s'en servent, et la même route les commute
     * selon le rôle — l'écran des personnes enrôlées étant servi aussi bien à
     * l'agent qu'au responsable.
     *
     * @param  array<string, mixed>|null  $kyc
     * @return array{nom: ?string, prenom: ?string}
     */
    protected function formatDemandeur(bool $estPersonneMorale, ?array $kyc, ?object $submitter): array
    {
        if ($estPersonneMorale) {
            return [
                'nom' => $submitter?->name,
                'prenom' => $submitter?->first_name,
            ];
        }

        return [
            'nom' => $kyc['name'] ?? null,
            'prenom' => $kyc['first_name'] ?? null,
        ];
    }
}
