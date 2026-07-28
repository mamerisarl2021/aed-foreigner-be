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
            'token' => ['required', 'string', 'max:100'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
            'pin' => ['required', 'string', 'min:4', 'max:12'],
            'security_questions' => ['nullable', 'array'],
            'security_questions.*.question' => ['required_with:security_questions', 'string', 'max:255'],
            'security_questions.*.answer' => ['required_with:security_questions', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'token.required' => 'Le token de finalisation est obligatoire.',
            'password.required' => 'Le mot de passe est obligatoire.',
            'password.min' => 'Le mot de passe doit contenir au moins 8 caractères.',
            'pin.required' => 'Le code PIN est obligatoire.',
            'pin.min' => 'Le code PIN doit contenir au moins 4 caractères.',
        ];
    }
}
