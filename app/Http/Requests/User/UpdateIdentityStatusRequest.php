<?php

namespace App\Http\Requests\User;

use App\Http\Requests\ApiFormRequest;

class UpdateIdentityStatusRequest extends ApiFormRequest
{
    protected function validationMessage(): string
    {
        return 'Erreur de validation des données';
    }

    public function rules(): array
    {
        return [
            'id' => 'required|integer|exists:identities,id',
            'status' => 'required|in:APPROVED,WAITING_MANAGER,REJECTED,PENDING',
        ];
    }
}
