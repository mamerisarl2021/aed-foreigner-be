<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Prestataire de confiance habilité (PDF §7) — clé API hashée au repos.
 *
 * @property string $id
 * @property string $name
 * @property string $key_prefix
 * @property string $key_hash
 * @property Carbon|null $last_used_at
 * @property Carbon|null $revoked_at
 * @property string|null $created_by_user_id
 */
class PsceqClient extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'key_prefix',
        'key_hash',
        'last_used_at',
        'revoked_at',
        'created_by_user_id',
    ];

    /** @var list<string> */
    protected $hidden = [
        'key_hash',
    ];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
