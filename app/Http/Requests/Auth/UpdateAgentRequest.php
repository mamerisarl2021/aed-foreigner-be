<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiFormRequest;
use App\Rules\PhoneNumber;

class UpdateAgentRequest extends ApiFormRequest
{
    protected function validationMessage(): string
    {
        return 'Format de donnée invalide.';
    }

    public function rules(): array
    {
        $userId = $this->route('id');

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'first_name' => ['sometimes', 'string', 'max:255'],
            'role' => ['sometimes', 'string', 'in:AGENT,RESPONSABLE_DE_VALIDATION,MANAGER,AUDITEUR'],
            // Optional leading +; 8–20 digits after stripping spaces/dashes/parentheses. Example: +2290162405472
            'phonenumber' => ['sometimes', 'string', new PhoneNumber],
            'npi' => ['sometimes', 'string', 'max:10', 'unique:users,npi,'.$userId],
            'email' => ['sometimes', 'string', 'email', 'max:255', 'unique:users,email,'.$userId],
        ];
    }
}
