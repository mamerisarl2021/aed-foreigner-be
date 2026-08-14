<?php

declare(strict_types=1);

namespace App\Http\Requests\Enrollment;

use App\Http\Requests\ApiFormRequest;

class SendFinalisationOtpRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'npi' => ['required', 'string', 'max:50', 'regex:/^[0-9]+$/'],
            'token' => ['required', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'npi.required' => 'Le NPI est obligatoire.',
            'npi.regex' => 'Le NPI doit être composé de chiffres.',
            'token.required' => 'Le token de finalisation est obligatoire.',
        ];
    }
}
