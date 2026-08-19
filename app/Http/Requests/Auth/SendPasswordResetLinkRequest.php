<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiFormRequest;

class SendPasswordResetLinkRequest extends ApiFormRequest
{
    protected function validationMessage(): string
    {
        return 'Email invalide ou inexistant.';
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'exists:users,email'],
        ];
    }
}
