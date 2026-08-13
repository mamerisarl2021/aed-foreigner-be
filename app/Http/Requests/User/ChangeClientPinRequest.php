<?php

declare(strict_types=1);

namespace App\Http\Requests\User;

use App\Http\Requests\ApiFormRequest;

class ChangeClientPinRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'current_pin' => ['required', 'string', 'digits:4'],
            'pin' => ['required', 'string', 'digits:4', 'confirmed'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'current_pin.required' => 'Le PIN actuel est obligatoire.',
            'current_pin.digits' => 'Le PIN actuel doit contenir exactement 4 chiffres.',
            'pin.required' => 'Le nouveau PIN est obligatoire.',
            'pin.digits' => 'Le PIN doit contenir exactement 4 chiffres.',
            'pin.confirmed' => 'La confirmation du PIN ne correspond pas.',
        ];
    }
}
