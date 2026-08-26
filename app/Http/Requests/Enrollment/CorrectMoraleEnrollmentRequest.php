<?php

declare(strict_types=1);

namespace App\Http\Requests\Enrollment;

use App\Http\Requests\ApiFormRequest;
use App\Http\Requests\Concerns\MergesRouteId;
use App\Models\EnrollmentRequest;
use Dedoc\Scramble\Attributes\IgnoreParam;

#[IgnoreParam('id')]
class CorrectMoraleEnrollmentRequest extends ApiFormRequest
{
    use MergesRouteId;

    public function authorize(): bool
    {
        $enrollment = EnrollmentRequest::query()->find($this->input('id'));
        if (! $enrollment instanceof EnrollmentRequest) {
            return true;
        }

        return $this->user()?->can('correctMorale', $enrollment) ?? false;
    }

    protected function validationMessage(): string
    {
        return 'Format de donnée invalide.';
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $isLegalRep = filter_var($this->input('is_legal_representative', true), FILTER_VALIDATE_BOOLEAN);

        return [
            'id' => ['required', 'uuid', 'exists:enrollment_requests,id'],
            'legal_name' => ['required', 'string', 'max:255'],
            'legal_form' => ['nullable', 'string', 'max:255'],
            'country_of_incorporation' => ['required', 'string', 'max:255'],
            'registration_number' => ['required', 'string', 'max:100'],
            'incorporation_date' => ['nullable', 'date'],
            'headquarters_address' => ['required', 'string', 'max:500'],
            'activity_sector' => ['required', 'string', 'max:255'],
            'legal_representative_name' => ['required', 'string', 'max:255'],
            'legal_representative_first_name' => ['required', 'string', 'max:255'],
            'is_legal_representative' => ['required', 'boolean'],
            // Extrait du registre de commerce. PDF uniquement, 5 Mo maximum.
            'trade_register_extract' => ['required', 'file', 'mimes:pdf', 'max:5120'],
            // Statuts de l'entreprise (facultatif). PDF uniquement, 5 Mo maximum.
            'statutes' => ['nullable', 'file', 'mimes:pdf', 'max:5120'],
            // Procuration, exigée si is_legal_representative=false. PDF uniquement, 5 Mo maximum.
            'procuration' => [$isLegalRep ? 'nullable' : 'required', 'file', 'mimes:pdf', 'max:5120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'trade_register_extract.required' => 'L\'extrait du registre de commerce est obligatoire.',
            'trade_register_extract.mimes' => 'L\'extrait du registre de commerce doit être un fichier PDF.',
            'trade_register_extract.max' => 'L\'extrait du registre de commerce ne doit pas dépasser 5 Mo.',
            'statutes.mimes' => 'Les statuts doivent être un fichier PDF.',
            'statutes.max' => 'Les statuts ne doivent pas dépasser 5 Mo.',
            'procuration.required' => 'La procuration est obligatoire lorsque le demandeur n\'est pas le représentant légal.',
            'procuration.mimes' => 'La procuration doit être un fichier PDF.',
            'procuration.max' => 'La procuration ne doit pas dépasser 5 Mo.',
        ];
    }
}
