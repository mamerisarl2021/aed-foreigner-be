<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiFormRequest;

class ResetPasswordRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'token' => 'required|string|max:100',
            'email' => 'required|email|max:255',
            'password' => 'required|confirmed|min:8|max:255',
        ];
    }
}
