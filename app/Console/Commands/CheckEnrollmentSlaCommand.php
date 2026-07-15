<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Enrollment\EnrollmentSlaService;
use Illuminate\Console\Command;

class CheckEnrollmentSlaCommand extends Command
{
    protected $signature = 'enrollment:check-sla';

    protected $description = 'Evaluate enrollment SLA thresholds and notify configured roles on level changes';

    public function handle(EnrollmentSlaService $slaService): int
    {
        $result = $slaService->checkAndNotify();

        $this->info(sprintf(
            'Enrollment SLA check complete: %d open demandes scanned, %d alert levels updated.',
            $result['checked'],
            $result['updated']
        ));

        return self::SUCCESS;
    }
}
