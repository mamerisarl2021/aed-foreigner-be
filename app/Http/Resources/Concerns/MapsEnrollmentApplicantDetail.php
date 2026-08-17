<?php

declare(strict_types=1);

namespace App\Http\Resources\Concerns;

use App\Models\EnrollmentRequest;
use App\Models\User;

trait MapsEnrollmentApplicantDetail
{
    /**
     * @return array<string, mixed>
     */
    protected function physiqueDetail(): array
    {
        $kyc = $this->kyc_data ?? [];
        $enrollment = $this->resource instanceof EnrollmentRequest ? $this->resource : null;

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
                'email_verifie' => $enrollment?->isEmailVerified() ?? false,
                'telephone_verifie' => $enrollment?->isPhoneVerified() ?? false,
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
        $enrollment = $this->resource;
        if (! $enrollment instanceof EnrollmentRequest) {
            return [
                'demandeur' => ['nom' => null, 'prenom' => null],
                'informations_entreprise' => [],
            ];
        }

        $kyc = is_array($enrollment->kyc_data) ? $enrollment->kyc_data : [];
        $submitter = ($enrollment->relationLoaded('submittedBy') && $enrollment->submittedBy instanceof User)
            ? $enrollment->submittedBy
            : null;
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
                'email' => $enrollment->email,
                'date_creation' => $kyc['incorporation_date'] ?? null,
                'pays_origine' => $kyc['country_of_incorporation'] ?? null,
                'forme_juridique' => $kyc['legal_form'] ?? null,
                'telephone' => $enrollment->phonenumber,
                'mandataire' => (bool) ($kyc['is_legal_representative'] ?? false),
                'adresse_siege_social' => $kyc['headquarters_address'] ?? null,
                'nom_prenoms_mandataire' => $mandataireLabel,
                'numero_immatriculation_legal' => $kyc['registration_number'] ?? null,
                'secteur_activite' => $kyc['activity_sector'] ?? null,
            ],
        ];
    }

    /**
     * Vérification croisée déjà calculée par EnrollmentReviewQueryService::show.
     *
     * @return list<array<string, mixed>>
     */
    protected function similarEnrollments(EnrollmentRequest $enrollment): array
    {
        $similar = $enrollment->getAttribute('similar_enrollments');

        return is_array($similar) ? array_values($similar) : [];
    }
}
