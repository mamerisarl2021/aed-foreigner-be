<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class EnrollmentRejectMotif extends Model
{
    use HasUuids;

    protected $fillable = [
        'title',
        'description',
    ];
}
