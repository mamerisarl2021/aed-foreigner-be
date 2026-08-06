<?php

declare(strict_types=1);

namespace App\Http\Requests\Enrollment;

use App\Http\Requests\ApiFormRequest;
use App\Http\Requests\Concerns\MergesRouteId;
use Illuminate\Validation\Rule;

class ValidationEnrollmentRequest extends ApiFormRequest
{
    use MergesRouteId;

    protected function validationMessage(): string
    {
        return 'Format de donnée invalide.';
    }

    public function rules(): array
    {
        $motifIdRules = ['required', 'uuid', Rule::exists('enrollment_reject_motifs', 'id')];

        $rules = [
            'id' => ['required', 'uuid', 'exists:enrollment_requests,id'],
            'decision' => ['required', 'string', Rule::in(['APPROUVEE', 'REJET_CONFIRME', 'RETOUR_AGENT'])],
            'commentaire' => ['nullable', 'string', 'max:1000'],
        ];

        if ($this->input('decision') === 'RETOUR_AGENT') {
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
