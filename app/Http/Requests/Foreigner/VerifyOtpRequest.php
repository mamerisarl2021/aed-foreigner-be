<?php

namespace App\Http\Requests\Foreigner;

use App\Http\Requests\ApiFormRequest;

class VerifyOtpRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'email' => 'required_without:phonenumber|nullable|email',
            'phonenumber' => 'required_without:email|nullable|string|min:8|max:20',
            'otp' => 'required|string',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required_without' => 'L\'adresse email ou le numéro de téléphone est obligatoire.',
            'phonenumber.required_without' => 'L\'adresse email ou le numéro de téléphone est obligatoire.',
            'otp.required' => 'Le code OTP est obligatoire.',
        ];
    }
}
