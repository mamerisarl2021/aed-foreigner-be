<?php

namespace App\Http\Requests\IdentityReview;

use App\Http\Requests\ApiFormRequest;

class RejectIdentityRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'stage' => 'required|in:KYC,STRUCTURE',
            'reasons' => 'required|array|min:1',
            'comments' => 'sometimes|string|nullable',
        ];
    }
}
