<?php

declare(strict_types=1);

namespace App\Http\Requests\User;

use App\Http\Requests\ApiFormRequest;
use App\Http\Requests\Concerns\MergesRouteId;
use Dedoc\Scramble\Attributes\IgnoreParam;
use Illuminate\Validation\Rule;

#[IgnoreParam('id')]
class UpdateUserProfileRequest extends ApiFormRequest
{
    use MergesRouteId;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'id' => ['required', 'uuid', 'exists:users,id'],
            'profile' => ['nullable', 'file', 'mimes:png,jpeg,jpg', 'max:2048'],
            'email' => [
                'required',
                'email',
                Rule::unique('users')->ignore($this->route('id')),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => 'L\'adresse email est obligatoire.',
            'email.unique' => 'Cette adresse email est déjà utilisée.',
            'profile.mimes' => 'La photo de profil doit être au format png, jpeg ou jpg.',
        ];
    }
}
