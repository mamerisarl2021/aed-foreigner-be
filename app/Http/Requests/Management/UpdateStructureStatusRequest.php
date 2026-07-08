<?php

namespace App\Http\Requests\Management;

use App\Http\Requests\ApiFormRequest;

class UpdateStructureStatusRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'structures' => 'required|array',
            'structures.*.id' => 'required|integer|exists:structures,id',
            'structures.*.status' => 'required|in:APPROVED,REJECTED,PENDING',
        ];
    }
}
