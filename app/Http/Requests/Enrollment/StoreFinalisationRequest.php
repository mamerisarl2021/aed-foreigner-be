<?php

declare(strict_types=1);

namespace App\Http\Requests\Enrollment;

use App\Http\Requests\ApiFormRequest;

class StoreFinalisationRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'npi' => ['required', 'string', 'max:50', 'regex:/^[0-9]+$/'],
            'token' => ['required', 'string', 'max:100'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
            'security_questions' => ['required', 'array', 'min:2'],
            'security_questions.*.question' => ['required', 'string', 'max:255'],
            'security_questions.*.answer' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'npi.required' => 'Le NPI est obligatoire.',
            'npi.regex' => 'Le NPI doit être composé de chiffres.',
            'token.required' => 'Le token de finalisation est obligatoire.',
            'password.required' => 'Le mot de passe est obligatoire.',
            'password.min' => 'Le mot de passe doit contenir au moins 8 caractères.',
            'security_questions.required' => 'Les questions de sécurité sont obligatoires.',
            'security_questions.min' => 'Deux questions de sécurité sont requises.',
        ];
    }
}
