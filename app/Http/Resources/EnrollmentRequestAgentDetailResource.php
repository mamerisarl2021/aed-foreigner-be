<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\EnrollmentStatus;
use App\Http\Resources\Concerns\FormatsEnrollmentDocuments;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Agent detail view — fields aligned with backoffice UI per type. */
class EnrollmentRequestAgentDetailResource extends JsonResource
{
    use FormatsEnrollmentDocuments;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $base = [
            'id' => $this->id,
            'type' => $this->type,
            'statut' => $this->status,
            'date_soumission' => $this->created_at,
            'agent_responsable' => $this->relationLoaded('assignedAgent')
                ? $this->formatAgent($this->assignedAgent)
                : null,
            'peut_prendre_en_charge' => $this->status === EnrollmentStatus::EnAttente->value
                && $this->assigned_agent_id === null,
            'peut_instruire' => $this->status === EnrollmentStatus::EnAttente->value
                && $this->assigned_agent_id !== null
                && (string) $this->assigned_agent_id === (string) $request->user()?->id,
        ];

        if ($this->isPersonneMorale()) {
            return array_merge($base, $this->moraleDetail(), [
                'pieces_jointes' => $this->moralePiecesJointes($this->documents),
            ]);
        }

        return array_merge($base, $this->physiqueDetail(), [
            'pieces_jointes' => $this->physiquePiecesJointes($this->documents),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function physiqueDetail(): array
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
    private function moraleDetail(): array
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
