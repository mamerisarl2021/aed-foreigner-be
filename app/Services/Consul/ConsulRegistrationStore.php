<?php

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

    public function getServiceId(): ?string
    {
        if (! Storage::exists($this->path())) {
            return null;
        }

        $data = json_decode(Storage::get($this->path()), true);

        return is_array($data) ? ($data['id'] ?? null) : null;
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
