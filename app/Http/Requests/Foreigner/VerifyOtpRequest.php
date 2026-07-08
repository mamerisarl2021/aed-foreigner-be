<?php

namespace App\Http\Requests\Foreigner;

use App\Http\Requests\ApiFormRequest;

class VerifyOtpRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'email' => 'required|email',
            'otp' => 'required|string',
        ];
    }
}
