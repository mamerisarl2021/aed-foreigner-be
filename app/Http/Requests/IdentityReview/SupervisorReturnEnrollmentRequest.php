<?php

declare(strict_types=1);

namespace App\Http\Requests\IdentityReview;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class SupervisorReturnEnrollmentRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'reasons' => ['required', 'array', 'min:1'],
            'reasons.*' => ['required', 'uuid', Rule::exists('enrollment_reject_motifs', 'id')],
            'comments' => ['sometimes', 'nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reasons.*.exists' => 'Un ou plusieurs motifs de rejet sont invalides.',
            'reasons.*.uuid' => 'Un ou plusieurs motifs de rejet sont invalides.',
        ];
    }
}
