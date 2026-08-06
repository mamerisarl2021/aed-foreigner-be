<?php

declare(strict_types=1);

namespace App\Http\Requests\Enrollment;

use App\Http\Requests\ApiFormRequest;
use App\Http\Requests\Concerns\MergesRouteId;
use Dedoc\Scramble\Attributes\IgnoreParam;
use Illuminate\Validation\Rule;

#[IgnoreParam('id')]
class UpdateEnrollmentRejectMotifRequest extends ApiFormRequest
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
        $id = $this->route('id');

        return [
            'id' => ['required', 'uuid', 'exists:enrollment_reject_motifs,id'],
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
