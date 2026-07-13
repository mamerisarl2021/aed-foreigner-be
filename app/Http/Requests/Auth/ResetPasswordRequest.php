<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiFormRequest;

class ResetPasswordRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'token' => 'required',
            'email' => 'required|email',
            'password' => 'required|confirmed|min:8',
        ];
    }
}
