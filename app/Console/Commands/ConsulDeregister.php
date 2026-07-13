<?php

namespace App\Console\Commands;

use App\Contracts\ConsulClientInterface;
use App\Exceptions\ConsulException;
use App\Services\Consul\ConsulRegistrationStore;
use Illuminate\Console\Command;

final class ConsulDeregister extends Command
{
    protected $signature = 'consul:deregister';

    protected $description = 'Deregister the microservice from Consul.';

    public function handle(
        ConsulClientInterface $consulClient,
        ConsulRegistrationStore $store,
    ): int {
        $serviceId = $store->getServiceId();

        if ($serviceId === null) {
            $this->warn('No locally registered Consul service found.');

            return self::SUCCESS;
        }

        $response = $consulClient->deregister($serviceId);

        if (! $response->successful()) {
            throw new ConsulException('Consul deregistration failed: '.$response->body());
        }

        $store->forget();

        $this->info("Deregistered from Consul: {$serviceId}");

        return self::SUCCESS;
    }
}
