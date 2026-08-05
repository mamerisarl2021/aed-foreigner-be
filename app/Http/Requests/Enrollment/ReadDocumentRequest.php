<?php

declare(strict_types=1);

namespace App\Http\Requests\Enrollment;

use App\Http\Requests\ApiFormRequest;

class ReadDocumentRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'recto' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'verso' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'recto.required' => 'Le recto de la pièce est obligatoire.',
            'recto.mimes' => 'Le recto doit être au format jpg, jpeg, png ou pdf.',
            'verso.mimes' => 'Le verso doit être au format jpg, jpeg, png ou pdf.',
        ];
    }
}
