<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;
use App\Models\PsceqClient;
use Illuminate\Support\Facades\Gate;

class ListPsceqClientsRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('viewAny', PsceqClient::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
