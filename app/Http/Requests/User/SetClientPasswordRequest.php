<?php

declare(strict_types=1);

namespace App\Http\Requests\User;

use App\Http\Requests\ApiFormRequest;

class SetClientPasswordRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
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
            'password.required' => 'Le mot de passe est obligatoire.',
            'npi.required' => 'Le NPI est obligatoire.',
            'type.in' => 'Le type doit être password ou pin.',
        ];
    }
}
