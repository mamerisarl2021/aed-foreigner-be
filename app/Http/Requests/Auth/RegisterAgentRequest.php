<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiFormRequest;
use App\Rules\PhoneNumber;

class RegisterAgentRequest extends ApiFormRequest
{
    protected function validationMessage(): string
    {
        return 'Format de donnée invalide.';
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'first_name' => ['required', 'string', 'max:255'],
            'role' => ['required', 'string', 'in:AGENT,RESPONSABLE_DE_VALIDATION,MANAGER'],
            // Optional leading +; 8–20 digits after stripping spaces/dashes/parentheses. Example: +2290162405472
            'phonenumber' => ['required', 'string', new PhoneNumber],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
        ];
    }
}
