<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiFormRequest;

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
            'role' => ['sometimes', 'string', 'in:LEVEL1,LEVEL2,LEVEL3,SUPERVISEUR,AUDITEUR'],
            'phonenumber' => ['sometimes', 'string', 'max:15'],
            'npi' => ['sometimes', 'string', 'max:10', 'unique:users,npi,'.$userId],
            'email' => ['sometimes', 'string', 'email', 'max:255', 'unique:users,email,'.$userId],
        ];
    }
}
