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

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
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
    ];

    protected $appends = ['link'];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function cases()
    {
        return $this->hasMany(Cases::class);
    }

    public function structures()
    {
        return $this->hasMany(Structure::class, 'manager_id');
    }

    public function userSubscriptions()
    {
        return $this->hasMany(UserSubscription::class);
    }

    public function identities()
    {
        return $this->hasMany(Identity::class);
    }

    public function signatures()
    {
        return $this->hasMany(Signature::class);
    }

    public function getLinkAttribute()
    {
        return $this->profile ? Storage::cloud()->temporaryUrl($this->profile, Carbon::now()->addDays(3)) : '';
    }

    // Dans app/Models/User.php
    public function structuresUsers()
    {
        return $this->belongsToMany(Structure::class, 'structure_users')
            ->withPivot('role', 'status', 'joined_at')
            ->withTimestamps();
    }

    public function activeStructures()
    {
        return $this->belongsToMany(Structure::class, 'structure_users')
            ->wherePivot('status', 'ACTIVE')
            ->withPivot('role', 'joined_at')
            ->withTimestamps();
    }

    public function invitations()
    {
        return $this->hasMany(StructureInvitation::class, 'user_id');
    }

    public function pendingInvitations()
    {
        return $this->hasMany(StructureInvitation::class, 'user_id')
            ->where('status', 'PENDING')
            ->where('expires_at', '>', Carbon::now());
    }
}
