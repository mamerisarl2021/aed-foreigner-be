<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiFormRequest;

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
            'role' => ['sometimes', 'string', 'in:AGENT,RESPONSABLE_DE_VALIDATION,MANAGER,AUDITEUR'],
            'phonenumber' => ['required', 'string', 'max:15'],
            'npi' => ['required', 'string', 'max:10', 'unique:users,npi'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
        ];
    }
}
