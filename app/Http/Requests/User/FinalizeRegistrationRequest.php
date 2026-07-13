<?php

namespace App\Http\Requests\User;

use App\Http\Requests\ApiFormRequest;
use App\Models\PendingRegistration;
use App\Rules\UniqueTypePerUser;
use Carbon\Carbon;
use Illuminate\Contracts\Validation\Validator;

class FinalizeRegistrationRequest extends ApiFormRequest
{
    public function rules(): array
    {
        $rules = [
            'registration_token' => 'required|string|exists:pending_registrations,registration_token',
        ];

        $pending = $this->pendingRegistration();
        if ($pending === null) {
            return $rules;
        }

        $isForeigner = $pending->user_data['is_foreigner'] ?? false;

        $rules = array_merge($rules, [
            'transaction_id' => 'required|string',
            'exp_date' => 'nullable|string',
            'birth_date' => 'nullable|string',
            'similarity' => 'required_if:type,ONLINE|string',
            'liveness' => 'required_if:type,ONLINE|string',
            'level' => ['required', 'string', 'in:SIMPLE,ADVANCED', new UniqueTypePerUser($this->input('user_id'), $this->input('level'))],
            'type' => ['required', 'string', 'in:IN_PERSON,ONLINE'],
            'selfie' => 'nullable|required_if:type,ONLINE|mimes:png,jpeg,jpg|max:6508',
            'recto' => 'nullable|required_if:type,ONLINE|mimes:png,jpeg,jpg|max:2048',
            'verso' => 'nullable|required_if:type,ONLINE|mimes:png,jpeg,jpg|max:2048',
        ]);

        if (! $isForeigner) {
            $rules['password'] = 'required|string';
            $rules['pin'] = 'required';
        } else {
            $rules = array_merge($rules, [
                'kyc.name' => 'sometimes|string',
                'kyc.first_name' => 'sometimes|string',
                'kyc.phonenumber' => 'sometimes|string',
                'kyc.nationality' => 'sometimes|string',
                'kyc.document_type' => 'sometimes|string|in:PASSPORT,RESIDENCE_PERMIT,OTHER',
                'kyc.document_number' => 'sometimes|string',
            ]);
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->filled('registration_token') && $this->pendingRegistration() === null) {
                $validator->errors()->add('registration_token', 'The selected registration token is invalid or expired.');
            }
        });
    }

    public function pendingRegistration(): ?PendingRegistration
    {
        $token = $this->input('registration_token');
        if (! is_string($token) || $token === '') {
            return null;
        }

        return PendingRegistration::where('registration_token', $token)
            ->where('status', 'PENDING')
            ->where('expires_at', '>', Carbon::now())
            ->first();
    }
}
