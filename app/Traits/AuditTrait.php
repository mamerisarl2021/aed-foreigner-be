<?php


namespace App\Traits;

use App\Models\ActivityLog;
use Exception;
use Illuminate\Support\Facades\Log;

trait AuditTrait
{
    public function logAction(string $action,string $details)
    {
        try {
            // Log de l'activité
            ActivityLog::create([
                'action' => $action,
                'details' => $details,
                'user_id' => auth()->user()->id,
                'user_role' => auth()->user()->role,
                'action_date' => now(),
            ]);
        } catch (Exception $e) {
            Log::error('Creating audit log failed: ' . $e->getMessage());
            return [
                'status' => false,
                'message' => 'Erreur pendant la création de la pièce jointe.'
            ];
        }
    }
}
