<?php

declare(strict_types=1);

namespace App\Services\Consul;

use App\Contracts\ConsulClientInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final class ConsulClient implements ConsulClientInterface
{
    public function __construct(
        private readonly ConsulTokenService $tokenService,
    ) {}

    /** @param  array<string, mixed>  $payload */
    public function register(array $payload): Response
    {
        return $this->request()->put($this->url('/v1/agent/service/register'), $payload);
    }

    public function deregister(string $serviceId): Response
    {
        return $this->request()->put($this->url("/v1/agent/service/deregister/{$serviceId}"));
    }

    public function agentChecks(): Response
    {
        return $this->request()->get($this->url('/v1/agent/checks'));
    }

    private function request(): PendingRequest
    {
        $request = Http::timeout($this->timeoutSeconds())->withHeaders([
            'X-Consul-Token' => $this->tokenService->getToken(),
            'Content-Type' => 'application/json',
        ]);

        if ($cacert = config('consul.cacert')) {
            $request = $request->withOptions(['verify' => $cacert]);
        }

        return $request;
    }

    private function timeoutSeconds(): int
    {
        $timeout = (int) config('consul.timeout', 10);

        return $timeout > 0 ? $timeout : 10;
    }

    private function url(string $path): string
    {
        return rtrim((string) config('consul.url'), '/').$path;
    }
}
