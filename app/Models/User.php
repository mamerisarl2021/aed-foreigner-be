<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;
use OwenIt\Auditing\Contracts\Auditable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements Auditable
{
    use HasApiTokens, HasFactory, HasRoles, Notifiable;
    use \OwenIt\Auditing\Auditable;

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
        'npi',
        'trustedx_registered_at',
        'security_questions',
    ];

    /** @var array<int, string> */
    protected $appends = ['link'];

    /** @var array<int, string> */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'trustedx_registered_at' => 'datetime',
            'security_questions' => 'array',
        ];
    }

    public function identities()
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
