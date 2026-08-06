<?php

declare(strict_types=1);

namespace App\Http\Requests\Enrollment;

use App\Http\Requests\ApiFormRequest;
use App\Http\Requests\Concerns\MergesRouteId;
use App\Models\EnrollmentRequest;
use Dedoc\Scramble\Attributes\IgnoreParam;

#[IgnoreParam('id')]
class VerifyMoralePhoneOtpRequest extends ApiFormRequest
{
    use MergesRouteId;

    public function authorize(): bool
    {
        $enrollment = EnrollmentRequest::find($this->route('id'));

        return $enrollment !== null
            && $this->user()?->can('viewOwnMorale', $enrollment);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'id' => ['required', 'uuid', 'exists:enrollment_requests,id'],
            'otp' => ['required', 'string', 'size:6'],
        ];
    }
}
