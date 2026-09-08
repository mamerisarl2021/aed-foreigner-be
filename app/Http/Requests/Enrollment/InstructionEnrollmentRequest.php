<?php

declare(strict_types=1);

namespace App\Http\Requests\Enrollment;

use App\Enums\AgentAvis;
use App\Http\Requests\ApiFormRequest;
use App\Http\Requests\Concerns\MergesRouteId;
use Dedoc\Scramble\Attributes\IgnoreParam;
use Illuminate\Validation\Rule;

#[IgnoreParam('id')]
class InstructionEnrollmentRequest extends ApiFormRequest
{
    use MergesRouteId;

    protected function validationMessage(): string
    {
        return 'Format de donnée invalide.';
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $motifIdRules = ['required', 'uuid', Rule::exists('enrollment_reject_motifs', 'id')];

        $rules = [
            'id' => ['required', 'uuid', 'exists:enrollment_requests,id'],
            // Avis de l'agent, pas un statut de demande : il alimente `agent_avis`
            // et laisse la demande en attente du responsable.
            'avis' => ['required', 'string', Rule::in(AgentAvis::values())],
            'commentaire' => ['nullable', 'string', 'max:1000'],
        ];

        if ($this->input('avis') === AgentAvis::Defavorable->value) {
            $rules['motif'] = ['required', 'array', 'min:1'];
            $rules['motif.*'] = $motifIdRules;
        } else {
            $rules['motif'] = ['nullable', 'array'];
            $rules['motif.*'] = ['uuid', Rule::exists('enrollment_reject_motifs', 'id')];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'motif.required' => 'Au moins un motif de rejet est requis.',
            'motif.*.exists' => 'Un ou plusieurs motifs de rejet sont invalides.',
            'motif.*.uuid' => 'Un ou plusieurs motifs de rejet sont invalides.',
        ];
    }
}
