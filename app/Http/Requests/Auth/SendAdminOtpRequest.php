<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiFormRequest;

class SendAdminOtpRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'email' => 'required|email|exists:users,email',
        ];
    }
}
