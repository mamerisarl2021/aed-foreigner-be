<?php

declare(strict_types=1);

namespace App\Http\Requests\Enrollment;

use App\Http\Requests\ApiFormRequest;
use App\Http\Requests\Concerns\MergesRouteId;
use Dedoc\Scramble\Attributes\IgnoreParam;

#[IgnoreParam('id')]
class VerifyMoraleEmailRequest extends ApiFormRequest
{
    use MergesRouteId;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'id' => ['required', 'uuid', 'exists:enrollment_requests,id'],
            'token' => ['required', 'string', 'size:64'],
        ];
    }
}
