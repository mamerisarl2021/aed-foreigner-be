<?php

declare(strict_types=1);

namespace App\Http\Requests\PasswordReset;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class ResetClientPasswordRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $isPin = $this->input('type') === 'pin';

        return [
            'token' => ['required', 'string', 'max:100'],
            'password' => $isPin
                ? ['required', 'string', 'digits:4']
                : ['required', 'string', 'min:8', 'max:255'],
            'npi' => ['required', 'string', 'max:50'],
            'type' => ['required', 'string', Rule::in(['password', 'pin'])],
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
            'password.digits' => 'Le PIN doit contenir exactement 4 chiffres.',
            'npi.required' => 'Le NPI est obligatoire.',
            'type.in' => 'Le type doit être password ou pin.',
        ];
    }
}
