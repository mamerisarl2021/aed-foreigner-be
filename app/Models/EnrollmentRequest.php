<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EnrollmentRequest extends Model
{
    protected $fillable = [
        'email',
        'phonenumber',
        'kyc_data',
        'documents',
        'liveness',
        'similarity',
        'status',
        'assigned_agent_id',
        'reject_stage',
        'reject_reasons',
        'review_comments',
        'type',
    ];

    protected function casts(): array
    {
        return [
            'kyc_data' => 'array',
            'documents' => 'array',
            'reject_reasons' => 'array',
        ];
    }
}
