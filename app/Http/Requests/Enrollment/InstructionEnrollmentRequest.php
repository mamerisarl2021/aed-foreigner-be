<?php

declare(strict_types=1);

namespace App\Http\Requests\Enrollment;

use App\Http\Requests\ApiFormRequest;
use App\Models\EnrollmentRejectMotif;
use Illuminate\Validation\Rule;

class InstructionEnrollmentRequest extends ApiFormRequest
{
    protected function validationMessage(): string
    {
        return 'Format de donnée invalide.';
    }

    public function rules(): array
    {
        $activeCodes = EnrollmentRejectMotif::query()->active()->pluck('code')->all();

        $rules = [
            'statut' => ['required', 'string', Rule::in(['VALIDATION_AGENT', 'REJET_AGENT'])],
            'commentaire' => ['nullable', 'string', 'max:1000'],
        ];

        if ($this->input('statut') === 'REJET_AGENT') {
            $rules['motif'] = ['required', 'array', 'min:1'];
            $rules['motif.*'] = ['required', 'string', Rule::in($activeCodes)];
        } else {
            $rules['motif'] = ['nullable', 'array'];
            $rules['motif.*'] = ['string', Rule::in($activeCodes)];
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
            'motif.*.in' => 'Un ou plusieurs motifs de rejet sont invalides.',
        ];
    }
}
