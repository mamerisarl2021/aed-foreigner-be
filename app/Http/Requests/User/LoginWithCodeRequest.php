<?php

namespace App\Http\Requests\User;

use App\Http\Requests\ApiFormRequest;

class LoginWithCodeRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'code' => 'required',
        ];
    }
}
