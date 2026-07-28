<?php

namespace App\Http\Requests\User;

use App\Http\Requests\ApiFormRequest;

class VerifyOtpRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'npi' => 'required|string|max:50',
            'otp' => 'required|string|size:6',
        ];
    }
}
