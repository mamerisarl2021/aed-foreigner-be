<?php

declare(strict_types=1);

namespace App\Http\Resources\Concerns;

trait MapsEnrollmentApplicantDetail
{
    /**
     * @return array<string, mixed>
     */
    protected function physiqueDetail(): array
    {
        $kyc = $this->kyc_data ?? [];

        return [
            'demandeur' => [
                'nom' => $kyc['name'] ?? null,
                'prenom' => $kyc['first_name'] ?? null,
            ],
            'informations' => [
                'nom' => $kyc['name'] ?? null,
                'prenoms' => $kyc['first_name'] ?? null,
                'numero_piece' => $kyc['document_number'] ?? null,
                'email' => $this->email,
                'telephone' => $this->phonenumber,
                'sexe' => $kyc['sexe'] ?? $kyc['sex'] ?? null,
                'date_naissance' => $kyc['date_of_birth'] ?? null,
                'pays_residence' => $kyc['country_of_residence'] ?? null,
                'type_piece' => $kyc['document_type'] ?? null,
                'lieu_naissance' => $kyc['place_of_birth'] ?? null,
                'nationalite' => $kyc['nationality'] ?? null,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function moraleDetail(): array
    {
        $kyc = $this->kyc_data ?? [];
        $submitter = $this->relationLoaded('submittedBy') ? $this->submittedBy : null;
        $repName = trim((string) ($kyc['legal_representative_name'] ?? ''));
        $repFirst = trim((string) ($kyc['legal_representative_first_name'] ?? ''));
        $mandataireLabel = trim($repName.' '.$repFirst) ?: null;

        return [
            'demandeur' => [
                'nom' => $submitter?->name,
                'prenom' => $submitter?->first_name,
            ],
            'informations_entreprise' => [
                'raison_sociale' => $kyc['legal_name'] ?? null,
                'email' => $this->email,
                'date_creation' => $kyc['incorporation_date'] ?? null,
                'pays_origine' => $kyc['country_of_incorporation'] ?? null,
                'forme_juridique' => $kyc['legal_form'] ?? null,
                'telephone' => $this->phonenumber,
                'mandataire' => (bool) ($kyc['is_legal_representative'] ?? false),
                'adresse_siege_social' => $kyc['headquarters_address'] ?? null,
                'nom_prenoms_mandataire' => $mandataireLabel,
                'numero_immatriculation_legal' => $kyc['registration_number'] ?? null,
            ],
        ];
    }
}
