<?php

declare(strict_types=1);

namespace App\Contracts;

use Illuminate\Http\Client\Response;

interface ConsulClientInterface
{
    public function register(array $payload): Response;

    public function deregister(string $serviceId): Response;
}
