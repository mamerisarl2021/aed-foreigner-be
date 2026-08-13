<?php

declare(strict_types=1);

namespace App\Http\Requests\Enrollment;

use App\Http\Requests\ApiFormRequest;
use App\Http\Requests\Concerns\MergesRouteId;
use App\Models\EnrollmentRequest;
use Dedoc\Scramble\Attributes\IgnoreParam;

#[IgnoreParam('id')]
class CorrectMoraleEnrollmentRequest extends ApiFormRequest
{
    use MergesRouteId;

    public function authorize(): bool
    {
        $enrollment = EnrollmentRequest::query()->find($this->input('id'));
        if (! $enrollment instanceof EnrollmentRequest) {
            return true;
        }

        return $this->user()?->can('correctMorale', $enrollment) ?? false;
    }

    protected function validationMessage(): string
    {
        return 'Format de donnée invalide.';
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $isLegalRep = filter_var($this->input('is_legal_representative', true), FILTER_VALIDATE_BOOLEAN);

        return [
            'id' => ['required', 'uuid', 'exists:enrollment_requests,id'],
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
        ];
    }
}
