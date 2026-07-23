<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rules\Password;

class ChangeStaffPasswordRequest extends ApiFormRequest
{
    protected function validationMessage(): string
    {
        return 'Format de donnée invalide.';
    }

    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ];
    }
}
