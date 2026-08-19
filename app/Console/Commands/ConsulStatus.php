<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\ConsulClientInterface;
use App\Exceptions\ConsulException;
use App\Services\Consul\ConsulRegistrationStore;
use Illuminate\Console\Command;

final class ConsulStatus extends Command
{
    protected $signature = 'consul:status';

    protected $description = 'Show the Consul health-check status for the locally registered service.';

    public function handle(
        ConsulClientInterface $consulClient,
        ConsulRegistrationStore $store,
    ): int {
        $registration = $store->get();

        if ($registration === null) {
            $this->warn('No locally registered Consul service found.');

            return self::FAILURE;
        }

        $serviceId = $registration['id'];
        $response = $consulClient->agentChecks();

        if (! $response->successful()) {
            throw new ConsulException('Consul agent checks failed: '.$response->body());
        }

        /** @var array<string, array<string, mixed>> $checks */
        $checks = $response->json() ?? [];
        $check = $this->findServiceCheck($checks, $serviceId);

        if ($check === null) {
            $this->error("No Consul check found for service: {$serviceId}");
            $this->line('Service ID:  '.$serviceId);
            $this->line('Name:        '.($registration['name'] !== '' ? $registration['name'] : '(unknown)'));

            return self::FAILURE;
        }

        $status = strtolower((string) ($check['Status'] ?? 'unknown'));
        $output = (string) ($check['Output'] ?? '');
        if (strlen($output) > 500) {
            $output = substr($output, 0, 497).'...';
        }

        $this->line('Service ID:  '.$serviceId);
        $this->line('Name:        '.($registration['name'] !== '' ? $registration['name'] : (string) ($check['ServiceName'] ?? '(unknown)')));
        $this->line('Check ID:    '.(string) ($check['CheckID'] ?? "service:{$serviceId}"));
        $this->line('Status:      '.$status);
        $this->line('Output:      '.($output !== '' ? $output : '-'));
        $this->line('Notes:       '.(string) (($check['Notes'] ?? '') !== '' ? $check['Notes'] : '-'));

        return $status === 'passing' ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string, array<string, mixed>>  $checks
     * @return array<string, mixed>|null
     */
    private function findServiceCheck(array $checks, string $serviceId): ?array
    {
        $expectedCheckId = 'service:'.$serviceId;

        if (isset($checks[$expectedCheckId]) && is_array($checks[$expectedCheckId])) {
            return $checks[$expectedCheckId];
        }

        foreach ($checks as $check) {
            if (! is_array($check)) {
                continue;
            }

            if (($check['ServiceID'] ?? null) === $serviceId
                || ($check['CheckID'] ?? null) === $expectedCheckId) {
                return $check;
            }
        }

        return null;
    }
}
