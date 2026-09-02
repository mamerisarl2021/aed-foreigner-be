<?php

declare(strict_types=1);

namespace App\Http\Requests\Psceq;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Support\Facades\Gate;

class SearchPsceqCompaniesRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('queryAsPsceq');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'min:2', 'max:255'],
        ];
    }
}
