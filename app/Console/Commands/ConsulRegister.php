<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\ConsulClientInterface;
use App\Exceptions\ConsulException;
use App\Services\Consul\ConsulRegistrationStore;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

final class ConsulRegister extends Command
{
    protected $signature = 'consul:register';

    protected $description = 'Register the microservice in Consul.';

    public function handle(
        ConsulClientInterface $consulClient,
        ConsulRegistrationStore $store,
    ): int {
        $name = (string) config('consul.service_name');
        $ip = (string) (config('consul.service_ip') ?: gethostbyname(gethostname()));
        $port = (int) config('consul.service_port');
        $id = $name.'-'.Str::random(8);

        $response = $consulClient->register([
            'ID' => $id,
            'Name' => $name,
            'Address' => $ip,
            'Port' => $port,
            'Tags' => config('consul.tags'),
            'Check' => [
                'HTTP' => "http://{$ip}:{$port}".config('consul.health_path'),
                'Interval' => config('consul.check_interval'),
                'Timeout' => config('consul.check_timeout'),
                'DeregisterCriticalServiceAfter' => config('consul.deregister_after'),
            ],
        ]);

        if (! $response->successful()) {
            throw new ConsulException('Consul registration failed: '.$response->body());
        }

        $store->save($id, $name);

        $this->info("Registered in Consul: {$id}");

        return self::SUCCESS;
    }
}
