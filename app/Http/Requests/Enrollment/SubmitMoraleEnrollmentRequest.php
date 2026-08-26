<?php

declare(strict_types=1);

namespace App\Http\Requests\Enrollment;

use App\Http\Requests\ApiFormRequest;
use App\Models\EnrollmentRequest;
use App\Rules\PhoneNumber;

class SubmitMoraleEnrollmentRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('submitMorale', EnrollmentRequest::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $isLegalRep = filter_var($this->input('is_legal_representative', true), FILTER_VALIDATE_BOOLEAN);

        return [
            'email' => ['required', 'email', 'max:255'],
            // Optional leading +; 8–20 digits after stripping spaces/dashes/parentheses. Example: +2290162405472
            'phonenumber' => ['required', 'string', new PhoneNumber],
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
            // Étape 3 — extrait du registre de commerce. PDF uniquement, 5 Mo maximum.
            'trade_register_extract' => ['required', 'file', 'mimes:pdf', 'max:5120'],
            // Étape 3 — statuts de l'entreprise (facultatif). PDF uniquement, 5 Mo maximum.
            'statutes' => ['nullable', 'file', 'mimes:pdf', 'max:5120'],
            // Étape 3 — procuration, exigée si is_legal_representative=false. PDF uniquement, 5 Mo maximum.
            'procuration' => [$isLegalRep ? 'nullable' : 'required', 'file', 'mimes:pdf', 'max:5120'],
            // Étape 2 — recto du document d'identité du demandeur, déjà vérifié par POST /kyc/document/verify. jpg, jpeg, png ou pdf, 5 Mo maximum.
            'recto' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            // Étape 2 — verso du document d'identité (facultatif). jpg, jpeg, png ou pdf, 5 Mo maximum.
            'verso' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
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
            'recto.required' => 'Le recto de la pièce d\'identité est obligatoire.',
            'recto.mimes' => 'Le recto doit être au format jpg, jpeg, png ou pdf.',
            'recto.max' => 'Le recto ne doit pas dépasser 5 Mo.',
            'verso.mimes' => 'Le verso doit être au format jpg, jpeg, png ou pdf.',
            'verso.max' => 'Le verso ne doit pas dépasser 5 Mo.',
        ];
    }
}
