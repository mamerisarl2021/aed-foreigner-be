<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;
use App\Http\Requests\Concerns\MergesRouteId;
use App\Models\PsceqClient;
use Dedoc\Scramble\Attributes\IgnoreParam;
use Illuminate\Support\Facades\Gate;

#[IgnoreParam('id')]
class DeletePsceqClientRequest extends ApiFormRequest
{
    use MergesRouteId;

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
            'id' => ['required', 'uuid', 'exists:psceq_clients,id'],
        ];
    }
}
