<?php

namespace App\Console\Commands;

use App\Models\StructureInvitation;
use Carbon\Carbon;
use Illuminate\Console\Command;

class CleanupExpiredInvitations extends Command
{
    protected $signature = 'invitations:cleanup';

    protected $description = 'Mark expired invitations as expired';

    public function handle()
    {
        $expiredCount = StructureInvitation::where('status', 'PENDING')
            ->where('expires_at', '<=', Carbon::now())
            ->update(['status' => 'EXPIRED']);

        $this->info("{$expiredCount} invitations expirées marquées.");

        return 0;
    }
}
