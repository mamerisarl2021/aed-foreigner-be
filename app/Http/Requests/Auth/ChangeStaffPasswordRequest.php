<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiFormRequest;
use App\Models\User;
use Illuminate\Validation\Rules\Password;

class ChangeStaffPasswordRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('changeStaffPassword', User::class) ?? false;
    }

    protected function validationMessage(): string
    {
        return 'Format de donnée invalide.';
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ];
    }
}
