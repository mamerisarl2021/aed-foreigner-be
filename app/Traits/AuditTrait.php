<?php

namespace App\Traits;

use App\Models\ActivityLog;
use Exception;
use Illuminate\Support\Facades\Log;

trait AuditTrait
{
    /**
     * No Request instance is available in this trait, so auth() is the accepted
     * exception to the "$request->user()" rule (guidelines §9.1).
     */
    public function logAction(string $action, string $details): void
    {
        try {
            ActivityLog::create([
                'action' => $action,
                'details' => $details,
                'user_id' => auth()->user()?->id,
                'user_role' => auth()->user()?->role,
                'action_date' => now(),
            ]);
        } catch (Exception $e) {
            Log::error('Creating audit log failed: '.$e->getMessage());
        }
    }
}
