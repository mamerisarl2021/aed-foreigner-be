<?php

declare(strict_types=1);

namespace App\Http\Requests\Audit;

use App\Http\Requests\ApiFormRequest;

/**
 * Optional filters: event, user_id, auditable_type, auditable_id, ip_address,
 * date_from, date_to, per_page.
 * date_from and date_to may be sent independently (single-sided bounds).
 */
class ListAuditLogsRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'event' => ['nullable', 'string', 'max:100'],
            'user_id' => ['nullable', 'string', 'max:100'],
            'auditable_type' => ['nullable', 'string', 'max:255'],
            'auditable_id' => ['nullable', 'string', 'max:100'],
            'ip_address' => ['nullable', 'string', 'max:45'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
