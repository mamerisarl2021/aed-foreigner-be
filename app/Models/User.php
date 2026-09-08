<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use OwenIt\Auditing\Contracts\Auditable;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property string $id
 * @property string|null $pin_hash
 * @property array<int, mixed>|null $security_questions
 * @property Carbon|null $last_login_at
 * @property Carbon|null $trustedx_registered_at
 *
 * @use HasFactory<UserFactory>
 */
class User extends Authenticatable implements Auditable
{
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasRoles, HasUuids, Notifiable;
    use \OwenIt\Auditing\Auditable;

    /** @var list<string> */
    protected array $auditExclude = [
        'password',
        'pin_hash',
        'remember_token',
        'security_questions',
    ];

    /** @var list<string> */
    protected $fillable = [
        'name',
        'first_name',
        'sexe',
        'phonenumber',
        'nationality',
        'profile',
        'email',
        'keycloak_id',
        'status',
        'last_login_at',
        'npi',
        'trustedx_registered_at',
        'security_questions',
        'pin_hash',
    ];

    /** @var array<string, mixed> */
    protected array $trustedxProfile = [];

    /** @var list<string> */
    protected $hidden = [
        'password',
        'pin_hash',
        'remember_token',
        'security_questions',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'pin_hash' => 'hashed',
            'last_login_at' => 'datetime',
            'trustedx_registered_at' => 'datetime',
            'security_questions' => 'array',
        ];
    }

    /** @return HasMany<Identity, $this> */
    public function identities(): HasMany
    {
        return $this->hasMany(Identity::class);
    }

    /**
     * Identity attributes returned by TrustedX, exposed on serialization only.
     *
     * They are deliberately kept out of the attribute bag: `last_name` and
     * `pki_id` have no column on `users`, so writing them as attributes makes
     * the next save() emit an UPDATE on unknown columns.
     *
     * @param  array<string, mixed>  $profile
     */
    public function withTrustedxProfile(array $profile): static
    {
        $this->trustedxProfile = $profile;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_merge(parent::toArray(), $this->trustedxProfile);
    }
}
