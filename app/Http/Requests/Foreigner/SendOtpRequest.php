<?php

namespace App\Http\Requests\Foreigner;

use App\Http\Requests\ApiFormRequest;
use App\Rules\PhoneNumber;

class SendOtpRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'email' => 'required_without:phonenumber|nullable|email|unique:users,email',
            // Optional leading +; 8–20 digits after stripping spaces/dashes/parentheses. Example: +2290162405472
            'phonenumber' => ['required_without:email', 'nullable', 'string', new PhoneNumber],
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
            'email.unique' => 'Cette adresse email est déjà utilisée.',
        ];
    }
}
