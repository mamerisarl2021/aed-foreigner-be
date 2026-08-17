<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AgentAvis;
use App\Enums\EnrollmentStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * @property array<string, mixed>|null $kyc_data
 * @property array<string, mixed>|null $documents
 * @property array<string, mixed>|null $analysis_details
 * @property string|null $liveness
 * @property string|null $similarity
 * @property string|null $risk_score
 * @property EnrollmentStatus $status
 * @property AgentAvis|null $agent_avis
 * @property list<string>|null $reject_reasons
 * @property list<string>|null $return_reasons
 * @property Carbon|null $selfie_captured_at
 * @property Carbon|null $returned_at
 * @property Carbon|null $agent_decided_at
 * @property Carbon|null $sla_deadline_at
 * @property Carbon|null $correction_deadline_at
 * @property Carbon|null $correction_reminder_sent_at
 * @property-read User|null $assignedAgent
 * @property-read User|null $assignedResponsable
 * @property-read User|null $submittedBy
 * @property-read EnrolledCompany|null $enrolledCompany
 */
class EnrollmentRequest extends Model implements Auditable
{
    use HasUuids;
    use \OwenIt\Auditing\Auditable;

    /** @var list<string> */
    protected array $auditExclude = [
        'email_verification_token',
    ];

    protected $fillable = [
        'tracking_code',
        'email',
        'phonenumber',
        'kyc_data',
        'documents',
        'liveness',
        'similarity',
        'risk_score',
        'analysis_details',
        'selfie_captured_at',
        'status',
        'agent_avis',
        'assigned_agent_id',
        'assigned_responsable_id',
        'agent_decided_at',
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
        'correction_deadline_at',
        'correction_reminder_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'kyc_data' => 'array',
            'documents' => 'array',
            'analysis_details' => 'array',
            'selfie_captured_at' => 'datetime',
            'reject_reasons' => 'array',
            'return_reasons' => 'array',
            'status' => EnrollmentStatus::class,
            'agent_avis' => AgentAvis::class,
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'verification_deadline_at' => 'datetime',
            'visio_requested_at' => 'datetime',
            'visio_completed_at' => 'datetime',
            'returned_at' => 'datetime',
            'agent_decided_at' => 'datetime',
            'sla_deadline_at' => 'datetime',
            'correction_deadline_at' => 'datetime',
            'correction_reminder_sent_at' => 'datetime',
        ];
    }

    public function assignedAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_agent_id');
    }

    public function assignedResponsable(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_responsable_id');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    /**
     * @return HasOne<EnrolledCompany, $this>
     */
    public function enrolledCompany(): HasOne
    {
        return $this->hasOne(EnrolledCompany::class);
    }

    public function statut(): EnrollmentStatus
    {
        return $this->status;
    }

    public function avisAgent(): ?AgentAvis
    {
        return $this->agent_avis;
    }

    public function isPersonneMorale(): bool
    {
        return $this->type === 'PERSONNE_MORALE';
    }

    public function applicantDisplayName(): string
    {
        if ($this->isPersonneMorale()) {
            return (string) ($this->kyc_data['legal_name'] ?? $this->email);
        }

        return (string) ($this->kyc_data['name'] ?? $this->email);
    }

    public function isContactVerificationComplete(): bool
    {
        return $this->email_verified_at !== null && $this->phone_verified_at !== null;
    }
}
