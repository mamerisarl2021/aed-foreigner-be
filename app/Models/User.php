<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;
use OwenIt\Auditing\Contracts\Auditable;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property string $id
 * @property string|null $pin_hash
 * @property array<int, mixed>|null $security_questions
 * @property \Illuminate\Support\Carbon|null $last_login_at
 * @property \Illuminate\Support\Carbon|null $trustedx_registered_at
 */
class User extends Authenticatable implements Auditable
{
    use HasApiTokens, HasFactory, HasRoles, HasUuids, Notifiable;
    use \OwenIt\Auditing\Auditable;

    /** @var list<string> */
    protected array $auditExclude = [
        'password',
        'pin_hash',
        'remember_token',
        'security_questions',
    ];

    /** @var array<int, string> */
    protected $fillable = [
        'name',
        'first_name',
        'sexe',
        'phonenumber',
        'nationality',
        'profile',
        'email',
        'status',
        'must_change_password',
        'last_login_at',
        'npi',
        'trustedx_registered_at',
        'security_questions',
        'pin_hash',
    ];

    /** @var array<int, string> */
    protected $appends = ['link'];

    /** @var array<int, string> */
    protected $hidden = [
        'password',
        'pin_hash',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'pin_hash' => 'hashed',
            'must_change_password' => 'boolean',
            'last_login_at' => 'datetime',
            'trustedx_registered_at' => 'datetime',
            'security_questions' => 'array',
        ];
    }

    public function identities(): HasMany
    {
        return $this->hasMany(Identity::class);
    }

    public function getLinkAttribute(): string
    {
        return $this->profile
            ? Storage::cloud()->temporaryUrl($this->profile, Carbon::now()->addDays(3))
            : '';
    }
}
