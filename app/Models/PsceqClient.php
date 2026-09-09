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
 * @property string|null $raison_sociale
 * @property string|null $rccm
 * @property string|null $pays
 * @property string|null $adresse_siege
 * @property string|null $site_web
 * @property string|null $email
 * @property string|null $telephone
 * @property string|null $point_focal_nom
 * @property string|null $point_focal_prenom
 * @property string|null $point_focal_fonction
 * @property string|null $point_focal_email
 * @property string|null $point_focal_telephone
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
        'raison_sociale',
        'rccm',
        'pays',
        'adresse_siege',
        'site_web',
        'email',
        'telephone',
        'point_focal_nom',
        'point_focal_prenom',
        'point_focal_fonction',
        'point_focal_email',
        'point_focal_telephone',
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
