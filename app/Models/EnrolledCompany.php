<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Entreprise étrangère enrôlée (PDF §6) — distincte de la demande.
 *
 * @property array<string, mixed>|null $documents
 */
class EnrolledCompany extends Model implements Auditable
{
    use HasUuids;
    use \OwenIt\Auditing\Auditable;

    public const STATUS_ACTIVE = 'ACTIVE';

    protected $fillable = [
        'identifiant',
        'enrollment_request_id',
        'manager_user_id',
        'legal_name',
        'legal_form',
        'country_of_incorporation',
        'registration_number',
        'incorporation_date',
        'headquarters_address',
        'activity_sector',
        'legal_representative_name',
        'legal_representative_first_name',
        'company_email',
        'company_phone',
        'documents',
        'status',
        'approved_at',
        'approved_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'documents' => 'array',
            'incorporation_date' => 'date',
            'approved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<EnrollmentRequest, $this>
     */
    public function enrollmentRequest(): BelongsTo
    {
        return $this->belongsTo(EnrollmentRequest::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }
}
