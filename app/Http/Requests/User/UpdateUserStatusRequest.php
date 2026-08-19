<?php

declare(strict_types=1);

namespace App\Http\Requests\User;

use App\Http\Requests\ApiFormRequest;

class UpdateUserStatusRequest extends ApiFormRequest
{
    protected function validationMessage(): string
    {
        return 'Erreur de validation des données';
    }

    public function rules(): array
    {
        return [
            'users' => 'required|array',
            'users.*.id' => 'required|uuid|exists:users,id',
            'users.*.status' => 'required|in:ACTIVE,INACTIVE',
        ];
    }
}
