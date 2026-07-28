<?php

declare(strict_types=1);

namespace App\Http\Requests\PasswordReset;

use App\Http\Requests\ApiFormRequest;

class ResetClientCredentialsRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
            'password' => ['sometimes', 'nullable', 'string', 'min:8'],
            'pin' => ['sometimes', 'nullable', 'string', 'min:4'],
            'npi' => ['required', 'string', 'max:50'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'token.required' => 'Le token de réinitialisation est obligatoire.',
            'npi.required' => 'Le NPI est obligatoire.',
        ];
    }
}
