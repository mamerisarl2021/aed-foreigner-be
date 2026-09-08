<?php

declare(strict_types=1);

namespace App\Http\Resources\Concerns;

use App\Models\User;
use App\Support\CloudTemporaryUrl;
use Carbon\Carbon;
use Illuminate\Support\Str;

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

        return $this->cloudTemporaryUrl((string) $docs[$key]);
    }

    /**
     * Même fichier, mais servi en pièce jointe.
     *
     * L'attribut `download` du navigateur est ignoré sur un lien d'un autre
     * domaine : c'est donc au stockage de l'imposer, via l'en-tête de
     * disposition signé avec l'URL.
     *
     * @param  array<string, mixed>|null  $docs
     */
    protected function documentDownloadUrl(?array $docs, string $key, string $label): ?string
    {
        if (! is_array($docs) || empty($docs[$key])) {
            return null;
        }

        $extension = pathinfo((string) $docs[$key], PATHINFO_EXTENSION);
        $nom = Str::slug($label).($extension !== '' ? '.'.$extension : '');

        return $this->cloudTemporaryUrl(
            (string) $docs[$key],
            ['ResponseContentDisposition' => 'attachment; filename="'.$nom.'"'],
        );
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function cloudTemporaryUrl(string $path, array $options = []): string
    {
        return CloudTemporaryUrl::make($path, $options);
    }

    /**
     * @param  array<string, mixed>|null  $docs
     * @return list<array{type: string, label: string, url: string, url_telechargement: ?string}>
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
                $pieces[] = [
                    'type' => $key,
                    'label' => $label,
                    'url' => $url,
                    'url_telechargement' => $this->documentDownloadUrl($docs, $key, $label),
                ];
            }
        }

        return $pieces;
    }

    /**
     * @param  array<string, mixed>|null  $docs
     * @return list<array{type: string, label: string, url: string, url_telechargement: ?string}>
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
                $pieces[] = [
                    'type' => $key,
                    'label' => $label,
                    'url' => $url,
                    'url_telechargement' => $this->documentDownloadUrl($docs, $key, $label),
                ];
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
    protected function formatDemandeur(bool $estPersonneMorale, ?array $kyc, ?User $submitter): array
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

    /**
     * Colonnes de la table Demandes PM. Nulles sur une personne physique :
     * le contrat de liste reste unique pour l'agent et le responsable.
     *
     * @param  array<string, mixed>|null  $kyc
     * @return array{numero_suivi: ?string, raison_sociale: ?string, pays_origine: ?string}
     */
    protected function formatMoraleListColumns(bool $estPersonneMorale, ?array $kyc, ?string $trackingCode): array
    {
        $kyc ??= [];

        return [
            'numero_suivi' => $trackingCode,
            'raison_sociale' => $estPersonneMorale ? ($kyc['legal_name'] ?? null) : null,
            'pays_origine' => $estPersonneMorale ? ($kyc['country_of_incorporation'] ?? null) : null,
        ];
    }

    protected function delaiEcouleJours(?Carbon $createdAt): int
    {
        if ($createdAt === null) {
            return 0;
        }

        return max(0, (int) $createdAt->copy()->startOfDay()->diffInDays(now()->copy()->startOfDay()));
    }
}
