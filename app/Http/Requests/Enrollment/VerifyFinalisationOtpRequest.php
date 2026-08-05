<?php

declare(strict_types=1);

namespace App\Http\Requests\Enrollment;

use App\Http\Requests\ApiFormRequest;

class VerifyFinalisationOtpRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'numero_suivi' => ['required', 'string', 'max:32'],
            'otp' => ['required', 'string', 'size:6'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'numero_suivi.required' => 'Le numéro de suivi est obligatoire.',
            'otp.required' => 'Le code OTP est obligatoire.',
            'otp.size' => 'Le code OTP doit contenir 6 caractères.',
        ];
    }
}
