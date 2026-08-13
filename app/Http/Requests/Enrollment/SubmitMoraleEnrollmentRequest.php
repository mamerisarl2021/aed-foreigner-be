<?php

declare(strict_types=1);

namespace App\Http\Requests\Enrollment;

use App\Http\Requests\ApiFormRequest;
use App\Models\EnrollmentRequest;
use App\Rules\PhoneNumber;

class SubmitMoraleEnrollmentRequest extends ApiFormRequest
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
        $isLegalRep = filter_var($this->input('is_legal_representative', true), FILTER_VALIDATE_BOOLEAN);

        return [
            'email' => ['required', 'email', 'max:255'],
            // Optional leading +; 8–20 digits after stripping spaces/dashes/parentheses. Example: +2290162405472
            'phonenumber' => ['required', 'string', new PhoneNumber],
            'legal_name' => ['required', 'string', 'max:255'],
            'legal_form' => ['nullable', 'string', 'max:255'],
            'country_of_incorporation' => ['required', 'string', 'max:255'],
            'registration_number' => ['required', 'string', 'max:100'],
            'incorporation_date' => ['nullable', 'date'],
            'headquarters_address' => ['required', 'string', 'max:500'],
            'activity_sector' => ['required', 'string', 'max:255'],
            'legal_representative_name' => ['required', 'string', 'max:255'],
            'legal_representative_first_name' => ['required', 'string', 'max:255'],
            'is_legal_representative' => ['required', 'boolean'],
            'trade_register_extract' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
            'statutes' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
            'procuration' => [$isLegalRep ? 'nullable' : 'required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
            'selfie' => ['required', 'file', 'mimes:jpg,jpeg,png', 'max:5120'],
            'recto' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'verso' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ];
    }
}
