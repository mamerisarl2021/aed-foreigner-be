<?php

declare(strict_types=1);

namespace App\Http\Requests\Enrollment;

use App\Http\Requests\ApiFormRequest;
use App\Models\EnrollmentRequest;

class VerifyDocumentRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('submitMorale', EnrollmentRequest::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $fileRequired = (bool) config('services.regula.mock') ? 'nullable' : 'required';

        return [
            // Recto du document d'identité : jpg, jpeg, png ou pdf, 5 Mo maximum.
            'recto' => [$fileRequired, 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            // Verso du document d'identité (facultatif) : jpg, jpeg, png ou pdf, 5 Mo maximum.
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
            'recto.max' => 'Le recto ne doit pas dépasser 5 Mo.',
            'verso.mimes' => 'Le verso doit être au format jpg, jpeg, png ou pdf.',
            'verso.max' => 'Le verso ne doit pas dépasser 5 Mo.',
        ];
    }
}
