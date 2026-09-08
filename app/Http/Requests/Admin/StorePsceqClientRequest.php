<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;
use App\Models\PsceqClient;
use Illuminate\Support\Facades\Gate;

class StorePsceqClientRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', PsceqClient::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nom' => ['required', 'string', 'max:255'],
        ];
    }
}
