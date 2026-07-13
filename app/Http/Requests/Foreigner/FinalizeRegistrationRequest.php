<?php

namespace App\Http\Requests\Foreigner;

use App\Http\Requests\ApiFormRequest;

class FinalizeRegistrationRequest extends ApiFormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'type' => 'ONLINE',
            'level' => 'ADVANCED',
        ]);
    }

    public function rules(): array
    {
        return [
            'registration_token' => 'required|string|exists:pending_registrations,registration_token',
            'transaction_id' => 'required|string',
            'selfie' => 'nullable|required|mimes:png,jpeg,jpg|max:6508',
            'recto' => 'nullable|required|mimes:png,jpeg,jpg|max:2048',
            'verso' => 'nullable|required|mimes:png,jpeg,jpg|max:2048',
            'similarity' => 'required|string',
            'liveness' => 'required|string',
            'exp_date' => 'nullable|string',
            'birth_date' => 'nullable|string',
            'kyc.name' => 'sometimes|string',
            'kyc.first_name' => 'sometimes|string',
            'kyc.phonenumber' => 'sometimes|string',
            'kyc.nationality' => 'sometimes|string',
            'kyc.document_type' => 'sometimes|string|in:PASSPORT,RESIDENCE_PERMIT,OTHER',
            'kyc.document_number' => 'sometimes|string',
        ];
    }
}
