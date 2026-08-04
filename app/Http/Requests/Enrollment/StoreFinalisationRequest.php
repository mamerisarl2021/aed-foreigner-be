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
            'token' => ['nullable', 'string', 'max:100'],
            'numero_suivi' => ['nullable', 'string', 'max:32'],
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
            'password.required' => 'Le mot de passe est obligatoire.',
            'password.min' => 'Le mot de passe doit contenir au moins 8 caractères.',
            'security_questions.required' => 'Les questions de sécurité sont obligatoires.',
            'security_questions.min' => 'Deux questions de sécurité sont requises.',
        ];
    }
}
