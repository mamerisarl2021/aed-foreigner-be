<?php

declare(strict_types=1);

namespace App\Http\Requests\Enrollment;

use App\Http\Requests\ApiFormRequest;

class TrackEnrollmentRequest extends ApiFormRequest
{
    protected function validationMessage(): string
    {
        return 'Format de donnée invalide.';
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Code communiqué à la soumission et dans l'email de confirmation (PK…).
            'numero_suivi' => ['required', 'string', 'max:32'],
            // Email de la demande (physique) ou email officiel / demandeur (morale).
            'email' => ['required', 'email', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'numero_suivi.required' => 'Le numéro de suivi est obligatoire.',
            'email.required' => 'L\'adresse email est obligatoire.',
            'email.email' => 'L\'adresse email est invalide.',
        ];
    }
}
