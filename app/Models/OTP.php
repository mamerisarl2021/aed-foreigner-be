<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

class OTP extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;
    use HasFactory;
    
    protected $table = 'otps'; // Specify the table name if different from the model name

    protected $fillable = [
        'email',
        'npi',
        'otp',
        'valid_until',
    ];
}
