<?php

declare(strict_types=1);

namespace App\Http\Requests\User;

use App\Http\Requests\ApiFormRequest;

class VerifyOtpRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'npi' => 'required|string|max:50',
            'otp' => 'required|string|size:6',
        ];
    }
}
