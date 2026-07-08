<?php

namespace App\Http\Requests\Management;

use App\Http\Requests\ApiFormRequest;

class StoreStructureSubscriptionRequest extends ApiFormRequest
{
    protected function validationMessage(): string
    {
        return 'Validation Error.';
    }

    public function rules(): array
    {
        return [
            'structure_id' => 'required|exists:structures,id',
            'structure_package_id' => 'required|exists:structure_packages,id',
            'transaction_id' => 'required',
        ];
    }
}
