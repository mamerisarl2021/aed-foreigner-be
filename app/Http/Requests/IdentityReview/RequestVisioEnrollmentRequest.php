<?php

declare(strict_types=1);

namespace App\Http\Requests\IdentityReview;

use App\Http\Requests\ApiFormRequest;

class RequestVisioEnrollmentRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
