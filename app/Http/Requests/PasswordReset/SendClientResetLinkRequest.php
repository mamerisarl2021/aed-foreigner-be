<?php

declare(strict_types=1);

namespace App\Http\Requests\PasswordReset;

use App\Http\Requests\ApiFormRequest;

class SendClientResetLinkRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
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
            'npi.required' => 'Le NPI est obligatoire.',
            'type.required' => 'Le type est obligatoire.',
            'type.in' => 'Le type doit être password ou pin.',
        ];
    }
}
