<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OTP extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'otps';

    protected $fillable = [
        'email',
        'npi',
        'otp',
        'valid_until',
    ];
}
