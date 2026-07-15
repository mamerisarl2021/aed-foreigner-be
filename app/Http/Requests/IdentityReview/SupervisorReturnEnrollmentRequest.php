<?php

declare(strict_types=1);

namespace App\Http\Requests\IdentityReview;

use App\Http\Requests\ApiFormRequest;
use App\Models\EnrollmentRejectMotif;
use Illuminate\Validation\Rule;

class SupervisorReturnEnrollmentRequest extends ApiFormRequest
{
    public function rules(): array
    {
        $activeCodes = EnrollmentRejectMotif::query()->active()->pluck('code')->all();

        return [
            'reasons' => ['required', 'array', 'min:1'],
            'reasons.*' => ['required', 'string', Rule::in($activeCodes)],
            'comments' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
