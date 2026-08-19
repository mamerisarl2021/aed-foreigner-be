<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiFormRequest;
use App\Models\User;

class LogoutAdminRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('logoutStaff', User::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
