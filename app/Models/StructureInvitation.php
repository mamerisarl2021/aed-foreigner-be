<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StructureInvitation extends Model
{
    use HasFactory;

    protected $fillable = [
        'structure_id',
        'user_id',
        'invited_by',
        'email',
        'token',
        'role',
        'status',
        'expires_at',
        'accepted_at',
        'rejected_at',
        'message',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    // Relations
    public function structure()
    {
        return $this->belongsTo(Structure::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function inviter()
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    // Scopes
    public function scopePending($query)
    {
        return $query->where('status', 'PENDING')
            ->where('expires_at', '>', Carbon::now());
    }

    public function scopeExpired($query)
    {
        return $query->where('status', 'PENDING')
            ->where('expires_at', '<=', Carbon::now());
    }

    public function scopeAccepted($query)
    {
        return $query->where('status', 'ACCEPTED');
    }

    public function scopeRejected($query)
    {
        return $query->where('status', 'REJECTED');
    }

    // Méthodes d'aide
    public function isPending()
    {
        return $this->status === 'PENDING' && $this->expires_at > Carbon::now();
    }

    public function isExpired()
    {
        return $this->status === 'PENDING' && $this->expires_at <= Carbon::now();
    }

    public function isAccepted()
    {
        return $this->status === 'ACCEPTED';
    }

    public function isRejected()
    {
        return $this->status === 'REJECTED';
    }

    public function accept()
    {
        $this->update([
            'status' => 'ACCEPTED',
            'accepted_at' => Carbon::now(),
        ]);
    }

    public function reject()
    {
        $this->update([
            'status' => 'REJECTED',
            'rejected_at' => Carbon::now(),
        ]);
    }

    public function markAsExpired()
    {
        if ($this->isPending()) {
            $this->update(['status' => 'EXPIRED']);
        }
    }
}
