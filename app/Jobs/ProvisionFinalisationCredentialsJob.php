<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Enrollment\ForeignerFinalizationService;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;

final class ProvisionFinalisationCredentialsJob implements ShouldBeEncrypted, ShouldBeUnique, ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 120;

    /**
     * @param  array<int, array{question: string, answer: string}>  $securityQuestions
     */
    public function __construct(
        public readonly string $enrollmentRequestId,
        public readonly string $userId,
        public readonly string $npi,
        public readonly string $password,
        public readonly array $securityQuestions,
    ) {}

    public function uniqueId(): string
    {
        return $this->enrollmentRequestId;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [15, 60, 120];
    }

    public function handle(ForeignerFinalizationService $finalization): void
    {
        $finalization->provisionTrustedXCredentials(
            $this->enrollmentRequestId,
            $this->userId,
            $this->npi,
            $this->password,
            $this->securityQuestions,
        );
    }
}
