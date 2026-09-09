<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;
use App\Http\Requests\Concerns\MergesRouteId;
use App\Models\PsceqClient;
use Dedoc\Scramble\Attributes\IgnoreParam;
use Illuminate\Support\Facades\Gate;

#[IgnoreParam('id')]
class UpdatePsceqClientRequest extends ApiFormRequest
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
            'nom' => ['required', 'string', 'max:255'],
            'raison_sociale' => ['required', 'string', 'max:255'],
            'rccm' => ['required', 'string', 'max:100'],
            'pays' => ['required', 'string', 'max:100'],
            'adresse_siege' => ['required', 'string', 'max:255'],
            'site_web' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'telephone' => ['required', 'string', 'max:30'],
            'point_focal_nom' => ['required', 'string', 'max:255'],
            'point_focal_prenom' => ['required', 'string', 'max:255'],
            'point_focal_fonction' => ['required', 'string', 'max:255'],
            'point_focal_email' => ['required', 'email', 'max:255'],
            'point_focal_telephone' => ['required', 'string', 'max:30'],
        ];
    }
}
