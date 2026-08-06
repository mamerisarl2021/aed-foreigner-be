<?php

declare(strict_types=1);

namespace App\Http\Requests\Enrollment;

use App\Http\Requests\ApiFormRequest;
use App\Http\Requests\Concerns\MergesRouteId;

class SendMoralePhoneOtpRequest extends ApiFormRequest
{
    use MergesRouteId;

    protected function validationMessage(): string
    {
        return 'Format de donnée invalide.';
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'id' => ['required', 'uuid', 'exists:enrollment_requests,id'],
        ];
    }
}
