<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\EnrolledCompanyStatus;
use App\Http\Requests\ApiFormRequest;
use App\Http\Requests\Concerns\MergesRouteId;
use Dedoc\Scramble\Attributes\IgnoreParam;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

#[IgnoreParam('id')]
class UpdateEnrolledCompanyStatusRequest extends ApiFormRequest
{
    use MergesRouteId;

    public function authorize(): bool
    {
        return Gate::allows('updateEnrolledCompanyStatus');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'id' => ['required', 'uuid', 'exists:enrolled_companies,id'],
            'statut' => ['required', 'string', Rule::enum(EnrolledCompanyStatus::class)],
        ];
    }
}
