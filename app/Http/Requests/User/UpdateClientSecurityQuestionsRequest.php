<?php

declare(strict_types=1);

namespace App\Http\Requests\User;

use App\Http\Requests\ApiFormRequest;

class UpdateClientSecurityQuestionsRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string'],
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
            'current_password.required' => 'Le mot de passe actuel est obligatoire.',
            'security_questions.required' => 'Les questions de sécurité sont obligatoires.',
            'security_questions.min' => 'Deux questions de sécurité sont requises.',
        ];
    }
}
