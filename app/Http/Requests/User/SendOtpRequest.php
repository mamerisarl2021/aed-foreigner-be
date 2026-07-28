<?php

namespace App\Http\Requests\User;

use App\Http\Requests\ApiFormRequest;

class SendOtpRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'npi' => 'required|string|max:50|unique:users',
        ];
    }
}
