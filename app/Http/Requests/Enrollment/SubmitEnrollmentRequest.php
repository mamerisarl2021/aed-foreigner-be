<?php

declare(strict_types=1);

namespace App\Http\Requests\Enrollment;

use App\Http\Requests\ApiFormRequest;
use App\Rules\PhoneNumber;

class SubmitEnrollmentRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255'],
            // Optional leading +; 8–20 digits after stripping spaces/dashes/parentheses. Example: +2290162405472
            'phonenumber' => ['required', 'string', new PhoneNumber],
            'name' => ['required', 'string', 'max:255'],
            'first_name' => ['required', 'string', 'max:255'],
            'sexe' => ['required', 'string', 'in:M,F'],
            'date_of_birth' => ['required', 'date'],
            'place_of_birth' => ['required', 'string', 'max:255'],
            'nationality' => ['required', 'string', 'max:255'],
            'country_of_residence' => ['required', 'string', 'max:255'],
            'address' => ['required', 'string', 'max:500'],
            'document_type' => ['required', 'string', 'in:PASSPORT,CNI_ECOWAS'],
            'document_number' => ['required', 'string', 'max:100'],
            'selfie' => ['required', 'file', 'mimes:jpg,jpeg,png', 'max:5120'],
            'recto' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'verso' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'profile' => ['nullable', 'file', 'mimes:jpg,jpeg,png', 'max:5120'],
            'liveness' => ['nullable', 'string'],
            'similarity' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => 'L\'adresse email est obligatoire.',
            'phonenumber.required' => 'Le numéro de téléphone est obligatoire.',
            'name.required' => 'Le nom est obligatoire.',
            'first_name.required' => 'Le prénom est obligatoire.',
            'sexe.required' => 'Le sexe est obligatoire.',
            'sexe.in' => 'Le sexe doit être M ou F.',
            'date_of_birth.required' => 'La date de naissance est obligatoire.',
            'place_of_birth.required' => 'Le lieu de naissance est obligatoire.',
            'nationality.required' => 'La nationalité est obligatoire.',
            'country_of_residence.required' => 'Le pays de résidence est obligatoire.',
            'address.required' => 'L\'adresse de résidence est obligatoire.',
            'document_type.required' => 'Le type de pièce d\'identité est obligatoire.',
            'document_type.in' => 'Le type de pièce doit être PASSPORT ou CNI_ECOWAS.',
            'document_number.required' => 'Le numéro de pièce d\'identité est obligatoire.',
            'selfie.required' => 'La photo selfie est obligatoire.',
            'recto.required' => 'Le recto du document est obligatoire.',
        ];
    }
}
