<?php

namespace App\Http\Requests\Foreigner;

use App\Http\Requests\ApiFormRequest;

class InitRegistrationRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'email' => 'required|email',
            'profile' => 'sometimes|file|mimes:png,jpeg,jpg|max:2048',
        ];
    }
}
