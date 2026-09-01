<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\EnrolledCompany;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Fiche d'une entreprise enrôlée.
 *
 * Pendant de {@see EnrolledPersonDetailResource}. Aucune analyse KYC ici, et il
 * n'y en aura pas : la personne morale ne passe pas le liveness — son étape
 * d'identité se limite à la lecture du document de son représentant.
 *
 * @mixin EnrolledCompany
 */
class EnrolledCompanyDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'raison_sociale' => $this->legal_name,
            'identifiant' => $this->identifiant,
            'email' => $this->company_email,
            'telephone' => $this->company_phone,
            'pays_origine' => $this->country_of_incorporation,
            'date_enrolement' => $this->approved_at,
            'demande_id' => $this->enrollment_request_id,
            'informations' => [
                'forme_juridique' => $this->legal_form,
                'numero_immatriculation' => $this->registration_number,
                'date_creation' => $this->incorporation_date,
                'adresse_siege_social' => $this->headquarters_address,
                'secteur_activite' => $this->activity_sector,
                'nom_representant_legal' => $this->legal_representative_name,
                'prenom_representant_legal' => $this->legal_representative_first_name,
            ],
        ];
    }
}
