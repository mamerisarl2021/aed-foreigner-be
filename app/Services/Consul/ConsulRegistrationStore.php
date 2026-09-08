<?php

declare(strict_types=1);

namespace App\Services\Consul;

use Illuminate\Support\Facades\Storage;

final class ConsulRegistrationStore
{
    public function save(string $id, string $name): void
    {
        Storage::put($this->path(), json_encode([
            'id' => $id,
            'name' => $name,
            'registered_at' => now()->toIso8601String(),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array{id: string, name: string, registered_at: ?string}|null
     */
    public function get(): ?array
    {
        if (! Storage::exists($this->path())) {
            return null;
        }

        $data = json_decode((string) Storage::get($this->path()), true);

        if (! is_array($data) || ! is_string($data['id'] ?? null)) {
            return null;
        }

        return [
            'id' => $data['id'],
            'name' => is_string($data['name'] ?? null) ? $data['name'] : '',
            'registered_at' => is_string($data['registered_at'] ?? null) ? $data['registered_at'] : null,
        ];
    }

    public function getServiceId(): ?string
    {
        return $this->get()['id'] ?? null;
    }

    public function forget(): void
    {
        if (Storage::exists($this->path())) {
            Storage::delete($this->path());
        }
    }

    private function path(): string
    {
        return 'consul-service-id.json';
    }
}
