<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\ConsulClientInterface;
use App\Services\Consul\ConsulClient;
use App\Services\Consul\ConsulRegistrationStore;
use App\Services\Consul\ConsulTokenService;
use Illuminate\Support\ServiceProvider;

final class ConsulServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ConsulTokenService::class);
        $this->app->singleton(ConsulRegistrationStore::class);
        $this->app->singleton(ConsulClientInterface::class, ConsulClient::class);
    }
}
