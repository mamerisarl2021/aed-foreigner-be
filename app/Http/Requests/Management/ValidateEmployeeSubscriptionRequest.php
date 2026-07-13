<?php

namespace App\Http\Requests\Management;

use App\Http\Requests\ApiFormRequest;

class ValidateEmployeeSubscriptionRequest extends ApiFormRequest
{
    protected function validationMessage(): string
    {
        return 'Validation Error.';
    }

    public function rules(): array
    {
        return [
            'user_subscription_id' => 'required|exists:user_subscriptions,id',
        ];
    }
}
