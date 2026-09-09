<?php

declare(strict_types=1);

namespace App\Http\Requests\Configuration;

use App\Http\Requests\ApiFormRequest;

class ShowConfigurationRequest extends ApiFormRequest
{
    /**
     * Public GET — no query or body fields.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
