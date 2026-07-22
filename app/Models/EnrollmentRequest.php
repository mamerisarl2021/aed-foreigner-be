<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnrollmentRequest extends Model
{
    protected $fillable = [
        'email',
        'phonenumber',
        'kyc_data',
        'documents',
        'liveness',
        'similarity',
        'risk_score',
        'analysis_details',
        'status',
        'assigned_agent_id',
        'reject_stage',
        'reject_reasons',
        'review_comments',
        'type',
        'submitted_by_user_id',
        'email_verification_token',
        'email_verified_at',
        'phone_verified_at',
        'verification_deadline_at',
        'visio_notes',
        'visio_requested_at',
        'visio_completed_at',
        'returned_at',
        'return_reasons',
        'sla_deadline_at',
        'sla_alert_level',
    ];

    protected function casts(): array
    {
        return [
            'kyc_data' => 'array',
            'documents' => 'array',
            'analysis_details' => 'array',
            'reject_reasons' => 'array',
            'return_reasons' => 'array',
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'verification_deadline_at' => 'datetime',
            'visio_requested_at' => 'datetime',
            'visio_completed_at' => 'datetime',
            'returned_at' => 'datetime',
            'sla_deadline_at' => 'datetime',
        ];
    }

    public function assignedAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_agent_id');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    public function isPersonneMorale(): bool
    {
        return $this->type === 'PERSONNE_MORALE';
    }

    public function isContactVerificationComplete(): bool
    {
        return $this->email_verified_at !== null && $this->phone_verified_at !== null;
    }
}
