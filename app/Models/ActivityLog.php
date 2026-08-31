<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityLog extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'action_code',
        'description',
        'actor_user_id',
        'enrollment_request_id',
        'metadata',
        'ip_address',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /** @return BelongsTo<EnrollmentRequest, $this> */
    public function enrollmentRequest(): BelongsTo
    {
        return $this->belongsTo(EnrollmentRequest::class, 'enrollment_request_id');
    }
}
