<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * @property array<string, mixed>|null $proof
 * @property array<string, mixed>|null $analysis_details
 */
class Identity extends Model implements Auditable
{
    use HasUuids;
    use \OwenIt\Auditing\Auditable;

    protected $fillable = [
        'type',
        'level',
        'proof',
        'user_id',
        'status',
        'date',
        'risk_score',
        'analysis_details',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'proof' => 'array',
            'analysis_details' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
