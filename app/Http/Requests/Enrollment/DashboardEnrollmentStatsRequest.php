<?php

declare(strict_types=1);

namespace App\Http\Requests\Enrollment;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class DashboardEnrollmentStatsRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Granularité de la courbe d'évolution et des tendances. Défaut: semaine.
            'granularite' => ['nullable', 'string', Rule::in(['semaine', 'mois'])],
        ];
    }
}
