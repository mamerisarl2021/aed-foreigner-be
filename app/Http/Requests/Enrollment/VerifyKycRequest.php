<?php

declare(strict_types=1);

namespace App\Http\Requests\Enrollment;

use App\Http\Requests\ApiFormRequest;

class VerifyKycRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'phonenumber' => ['required', 'string'],
            'liveness' => ['nullable'],
            'similarity' => ['nullable', 'numeric'],
            'selfie' => ['nullable', 'file', 'mimes:jpg,jpeg,png', 'max:5120'],
            'recto' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'verso' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
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
            'selfie.mimes' => 'La photo selfie doit être au format jpg, jpeg ou png.',
            'recto.mimes' => 'Le recto doit être au format jpg, jpeg, png ou pdf.',
            'verso.mimes' => 'Le verso doit être au format jpg, jpeg, png ou pdf.',
        ];
    }
}
