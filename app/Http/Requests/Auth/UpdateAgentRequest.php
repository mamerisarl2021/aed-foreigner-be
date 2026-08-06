<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiFormRequest;
use App\Http\Requests\Concerns\MergesRouteId;
use App\Rules\PhoneNumber;
use Dedoc\Scramble\Attributes\IgnoreParam;
use Illuminate\Validation\Rule;

#[IgnoreParam('id')]
class UpdateAgentRequest extends ApiFormRequest
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
        $userId = $this->route('id');

        return [
            'id' => ['required', 'uuid', 'exists:users,id'],
            'name' => ['sometimes', 'string', 'max:255'],
            'first_name' => ['sometimes', 'string', 'max:255'],
            'role' => ['sometimes', 'string', 'in:AGENT,RESPONSABLE_DE_VALIDATION,MANAGER'],
            // Optional leading +; 8–20 digits after stripping spaces/dashes/parentheses. Example: +2290162405472
            'phonenumber' => ['sometimes', 'string', new PhoneNumber],
            'npi' => ['sometimes', 'string', 'max:10', Rule::unique('users', 'npi')->ignore($userId)],
            'email' => ['sometimes', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)],
        ];
    }
}
