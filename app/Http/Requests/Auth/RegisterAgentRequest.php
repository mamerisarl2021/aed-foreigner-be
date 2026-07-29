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
            'role' => ['required', 'string', 'in:AGENT,RESPONSABLE_DE_VALIDATION,MANAGER,AUDITEUR'],
            'phonenumber' => ['required', 'string', new PhoneNumber],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
        ];
    }
}
