<?php

declare(strict_types=1);

namespace App\Http\Requests\Signature;

use App\Http\Requests\ApiFormRequest;

class StoreMultipleSignatureRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'users' => 'required|array',
            'users.*.email' => 'required|exists:users,email',
            'users.*.location' => 'required|string',
            'users.*.toTimestamp' => 'nullable|boolean',
        ];
    }
}
