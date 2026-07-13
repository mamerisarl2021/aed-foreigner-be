<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

class Structure extends Model implements Auditable
{
    use HasFactory;
    use \OwenIt\Auditing\Auditable;

    protected $fillable = ['name', 'ifu', 'manager_id', 'searchbase', 'status'];

    public function manager()
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function attachments()
    {
        return $this->hasMany(Attachment::class);
    }

    public function userSubscriptions()
    {
        return $this->hasMany(UserSubscription::class);
    }

    public function structureSubscriptions()
    {
        return $this->hasMany(StructureSubscription::class);
    }

    // Dans app/Models/Structure.php
    public function employees()
    {
        return $this->belongsToMany(User::class, 'structure_users')
            ->withPivot('role', 'status', 'joined_at', 'invitation_message')
            ->withTimestamps();
    }

    public function activeEmployees()
    {
        return $this->belongsToMany(User::class, 'structure_users')
            ->wherePivot('status', 'ACTIVE')
            ->withPivot('role', 'joined_at')
            ->withTimestamps();
    }

    public function invitations()
    {
        return $this->hasMany(StructureInvitation::class);
    }

    public function pendingInvitations()
    {
        return $this->hasMany(StructureInvitation::class)
            ->where('status', 'PENDING')
            ->where('expires_at', '>', Carbon::now());
    }
}
