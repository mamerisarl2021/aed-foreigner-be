<?php

declare(strict_types=1);

namespace App\Http\Requests\Enrollment;

use App\Http\Requests\ApiFormRequest;
use App\Models\EnrollmentRequest;
use App\Rules\Enrollment\Iso8601Instant;
use App\Rules\PhoneNumber;

class VerifyKycRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null) {
            return true;
        }

        return $user->can('verifyPhysiqueKyc', EnrollmentRequest::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $mock = (bool) config('services.regula.mock');
        $fileRequired = $mock ? 'nullable' : 'required';

        return [
            'email' => ['required', 'email', 'max:255'],
            // Optional leading +; 8–20 digits after stripping spaces/dashes/parentheses. Example: +2290162405472
            'phonenumber' => ['required', 'string', new PhoneNumber],
            // Face liveness transaction id (optional). Legacy numeric "scores" are ignored by the orchestrator.
            'liveness' => ['nullable', 'string', 'max:128'],
            'liveness_transaction_id' => ['nullable', 'string', 'max:128'],
            // Deprecated client-supplied score — ignored for the OK/KO gate when not mocking.
            'similarity' => ['nullable', 'numeric'],
            'selfie' => [$fileRequired, 'file', 'mimes:jpg,jpeg,png', 'max:5120'],
            'recto' => [$fileRequired, 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'verso' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            // Instant ISO-8601 of the selfie / liveness capture (TZ required). Window: last 60 min / next 5 min. Defaults to KYC verification time.
            'capture_le' => ['nullable', 'string', new Iso8601Instant],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => 'L\'adresse email est obligatoire.',
            'phonenumber.required' => 'Le numéro de téléphone est obligatoire.',
            'selfie.required' => 'La photo selfie est obligatoire pour la vérification KYC.',
            'recto.required' => 'Le recto de la pièce est obligatoire pour la vérification KYC.',
            'selfie.mimes' => 'La photo selfie doit être au format jpg, jpeg ou png.',
            'recto.mimes' => 'Le recto doit être au format jpg, jpeg, png ou pdf.',
            'verso.mimes' => 'Le verso doit être au format jpg, jpeg, png ou pdf.',
        ];
    }
}
