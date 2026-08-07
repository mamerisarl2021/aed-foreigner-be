<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

class PasswordResetToken extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $table = 'password_resets';

    /** @var list<string> */
    protected array $auditExclude = [
        'token',
    ];

    protected $fillable = [
        'npi',
        'email',
        'token',
        'type',
        'created_at',
    ];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }
}
