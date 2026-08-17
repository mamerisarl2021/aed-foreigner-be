<?php

declare(strict_types=1);

namespace App\Jobs\Concerns;

trait RetriesWithBackoff
{
    public int $tries = 3;

    public int $timeout = 60;

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 60];
    }
}
