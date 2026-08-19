<?php

declare(strict_types=1);

namespace App\Http\Requests\User;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

/**
 * Web client login. The `redirect_uri` is not ours to choose: TrustedX binds
 * the authorization code to the exact value used at `/authorize`, so the
 * browser that started the flow is the only party that knows it. We accept it
 * from the client and check it against the configured allow-list.
 */
class ClientLoginRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => 'required|string',
            'redirect_uri' => [
                'required',
                'string',
                Rule::in((array) config('trustedx.allowed_redirect_urls')),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'redirect_uri.required' => "L'URL de redirection utilisée à l'authentification est obligatoire.",
            'redirect_uri.in' => "Cette URL de redirection n'est pas autorisée. Ajoutez-la à TX_REDIRECT_URL ou TX_ALLOWED_REDIRECT_URLS.",
        ];
    }
}
