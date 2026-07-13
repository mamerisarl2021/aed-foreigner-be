<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PendingRegistration extends Model
{
    protected $fillable = [
        'npi',
        'email',
        'profile_path',
        'user_data',
        'registration_token',
        'expires_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'user_data' => 'array',
            'expires_at' => 'datetime',
        ];
    }
}
