<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;
use App\Http\Requests\Concerns\MergesRouteFilename;

class DecryptDocumentRequest extends ApiFormRequest
{
    use MergesRouteFilename;

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
            'filename' => ['required', 'string', 'max:255', 'regex:/^[^\/\\\\]+$/', 'not_regex:/\.\./'],
        ];
    }
}
