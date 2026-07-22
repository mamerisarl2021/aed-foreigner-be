<?php

declare(strict_types=1);

namespace App\Http\Requests\Enrollment;

use Illuminate\Foundation\Http\FormRequest;

class VerifyMoraleEmailRequest extends FormRequest
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
            'token' => ['required', 'string', 'size:64'],
        ];
    }
}
