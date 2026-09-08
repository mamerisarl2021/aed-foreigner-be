<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

class OTP extends Model implements Auditable
{
    use HasUuids;
    use \OwenIt\Auditing\Auditable;

    protected $table = 'otps';

    /** @var list<string> */
    protected array $auditExclude = [
        'otp',
    ];

    protected $fillable = [
        'email',
        'npi',
        'otp',
        'valid_until',
    ];
}
