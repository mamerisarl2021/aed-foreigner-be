<?php

namespace App\Http\Requests\Foreigner;

use App\Http\Requests\ApiFormRequest;

class SendOtpRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'email' => 'required|email|unique:users,email',
        ];
    }
}
