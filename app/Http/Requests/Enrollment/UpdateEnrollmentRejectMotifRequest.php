<?php

declare(strict_types=1);

namespace App\Http\Requests\Enrollment;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class UpdateEnrollmentRejectMotifRequest extends ApiFormRequest
{
    protected function validationMessage(): string
    {
        return 'Format de donnée invalide.';
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $id = $this->route('id');

        return [
            'title' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('enrollment_reject_motifs', 'title')->ignore($id),
            ],
            'description' => ['sometimes', 'required', 'string'],
        ];
    }
}
