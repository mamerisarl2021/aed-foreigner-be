<?php

namespace App\Http\Requests\User;

use App\Http\Requests\ApiFormRequest;

class ApproveInPersonIdentityRequest extends ApiFormRequest
{
    protected function validationMessage(): string
    {
        return 'Erreur de validation des données';
    }

    public function rules(): array
    {
        return [
            'exp_date' => 'nullable|string',
            'birth_date' => 'nullable|string',
            'selfie' => 'nullable|required_if:type,ONLINE|mimes:png,jpeg,jpg|max:6508',
            'recto' => 'nullable|required_if:type,ONLINE|mimes:png,jpeg,jpg|max:2048',
            'verso' => 'nullable|required_if:type,ONLINE|mimes:png,jpeg,jpg|max:2048',
            'user_id' => 'sometimes|integer|exists:users,id',
            'type' => 'nullable|string|in:IN_PERSON,ONLINE',
            'id' => 'required|integer|exists:identities,id',
            'status' => 'required|in:APPROVED,WAITING_MANAGER,REJECTED,PENDING',
        ];
    }
}
