<?php

declare(strict_types=1);

namespace App\Http\Requests\Psceq;

use App\Http\Requests\ApiFormRequest;
use Dedoc\Scramble\Attributes\IgnoreParam;
use Illuminate\Support\Facades\Gate;

#[IgnoreParam('identifiant')]
class ShowPsceqCompanyRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('queryAsPsceq');
    }

    protected function prepareForValidation(): void
    {
        $identifiant = $this->route('identifiant');
        if (is_string($identifiant)) {
            $this->merge(['identifiant' => $identifiant]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'identifiant' => ['required', 'string', 'max:32'],
        ];
    }
}
