<?php

declare(strict_types=1);

namespace App\Http\Requests\IdentityReview;

use App\Http\Requests\ApiFormRequest;
use App\Models\EnrollmentRejectMotif;
use Illuminate\Validation\Rule;

class RejectIdentityRequest extends ApiFormRequest
{
    public function rules(): array
    {
        $activeCodes = EnrollmentRejectMotif::query()->active()->pluck('code')->all();

        return [
            'stage' => ['required', 'string', Rule::in(['KYC', 'DOCUMENT', 'BIOMETRY', 'COMPANY', 'REPRESENTATIVE', 'OTHER'])],
            'reasons' => ['required', 'array', 'min:1'],
            'reasons.*' => ['required', 'string', Rule::in($activeCodes)],
            'comments' => ['sometimes', 'nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'stage.in' => 'Le stage doit être KYC, DOCUMENT, BIOMETRY, COMPANY, REPRESENTATIVE ou OTHER.',
            'reasons.*.in' => 'Un ou plusieurs motifs de rejet sont invalides.',
        ];
    }
}
