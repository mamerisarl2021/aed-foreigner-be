<?php

declare(strict_types=1);

namespace App\Http\Requests\PasswordReset;

use App\Http\Requests\ApiFormRequest;

class ResetClientPasswordRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8'],
            'npi' => ['required', 'string', 'max:50'],
            'type' => ['required', 'string', 'in:password,pin'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'token.required' => 'Le token de réinitialisation est obligatoire.',
            'password.required' => 'Le mot de passe est obligatoire.',
            'password.min' => 'Le mot de passe doit contenir au moins 8 caractères.',
            'npi.required' => 'Le NPI est obligatoire.',
            'type.in' => 'Le type doit être password ou pin.',
        ];
    }
}
