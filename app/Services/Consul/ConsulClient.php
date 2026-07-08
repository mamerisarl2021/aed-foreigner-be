<?php

declare(strict_types=1);

namespace App\Services\Consul;

use App\Contracts\ConsulClientInterface;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final class ConsulClient implements ConsulClientInterface
{
    public function __construct(
        private readonly ConsulTokenService $tokenService,
    ) {}

    public function register(array $payload): Response
    {
        return $this->request()->put($this->url('/v1/agent/service/register'), $payload);
    }

    public function deregister(string $serviceId): Response
    {
        return $this->request()->put($this->url("/v1/agent/service/deregister/{$serviceId}"));
    }

    private function request()
    {
        $request = Http::withHeaders([
            'X-Consul-Token' => $this->tokenService->getToken(),
            'Content-Type' => 'application/json',
        ]);

        if ($cacert = config('consul.cacert')) {
            $request = $request->withOptions(['verify' => $cacert]);
        }

        return $request;
    }

    private function url(string $path): string
    {
        return rtrim((string) config('consul.url'), '/').$path;
    }
}
