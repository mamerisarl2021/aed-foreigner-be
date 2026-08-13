<?php

declare(strict_types=1);

namespace App\Http\Requests\Enrollment;

use App\Http\Requests\ApiFormRequest;
use App\Models\EnrollmentRequest;

class ListMoraleEnrollmentsRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('listOwnMorale', EnrollmentRequest::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Défaut: 15. Maximum: 100.
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
