<?php

declare(strict_types=1);

namespace App\Http\Requests\Enrollment;

use Illuminate\Foundation\Http\FormRequest;

class VerifyMoralePhoneOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        $enrollment = \App\Models\EnrollmentRequest::find($this->route('id'));

        return $enrollment !== null
            && $this->user()?->can('viewOwnMorale', $enrollment);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'otp' => ['required', 'string', 'size:6'],
        ];
    }
}
