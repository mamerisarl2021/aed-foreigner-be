<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\EnrollmentStatus;
use App\Http\Resources\Concerns\FormatsEnrollmentDocuments;
use App\Http\Resources\Concerns\MapsEnrollmentApplicantDetail;
use App\Http\Resources\Concerns\MapsEnrollmentKycAnalysis;
use App\Models\EnrollmentRequest;
use App\Support\EnrollmentStatusPresenter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Agent detail view — fields aligned with backoffice UI per type.
 *
 * @mixin EnrollmentRequest
 */
class EnrollmentRequestAgentDetailResource extends JsonResource
{
    use FormatsEnrollmentDocuments;
    use MapsEnrollmentApplicantDetail;
    use MapsEnrollmentKycAnalysis;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var EnrollmentRequest $enrollment */
        $enrollment = $this->resource;

        $base = [
            'id' => $this->id,
            // Code de suivi communiqué au demandeur : c'est par lui qu'on
            // désigne un dossier, l'UUID ne circulant qu'entre machines.
            'numero_suivi' => $this->tracking_code,
            'identifiant' => $this->enrolledCompany?->identifiant,
            'type' => $this->type,
            'statut' => $this->status->value,
            'statut_libelle' => EnrollmentStatusPresenter::labelFor($enrollment->statut(), $request->user()),
            'date_soumission' => $this->created_at,
            'agent_responsable' => $this->relationLoaded('assignedAgent')
                ? $this->formatAgent($this->assignedAgent)
                : null,
            // Avis déjà rendu par le niveau agent, `null` tant qu'il ne l'a pas été.
            'avis_agent' => $enrollment->agent_avis?->value,
            'avis_agent_libelle' => $enrollment->avisAgent()?->label(),
            'email_verifie' => $enrollment->isEmailVerified(),
            'telephone_verifie' => $enrollment->isPhoneVerified(),
            'peut_prendre_en_charge' => $this->status === EnrollmentStatus::EnAttenteAgent
                && $this->assigned_agent_id === null,
            'peut_instruire' => $this->status === EnrollmentStatus::EnCoursAgent
                && (string) $this->assigned_agent_id === (string) $request->user()?->id,
            // Vérification croisée (PDF §5.1) : déjà calculée au show, exposée ici.
            'similar_enrollments' => $this->similarEnrollments($enrollment),
        ];

        if ($this->isPersonneMorale()) {
            return array_merge($base, $this->moraleDetail(), [
                'pieces_jointes' => $this->moralePiecesJointes($this->documents),
                'analyse_kyc' => $this->analyseKycMorale($enrollment),
            ]);
        }

        return array_merge($base, $this->physiqueDetail(), [
            'pieces_jointes' => $this->physiquePiecesJointes($this->documents),
            'analyse_kyc' => $this->analyseKycPhysique($enrollment),
        ]);
    }
}
