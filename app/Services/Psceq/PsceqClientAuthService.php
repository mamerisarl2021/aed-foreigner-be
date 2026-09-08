<?php

declare(strict_types=1);

namespace App\Services\Psceq;

use App\Models\PsceqClient;
use App\Support\PsceqApiKey;
use Illuminate\Support\Facades\Hash;

final class PsceqClientAuthService
{
    public function authenticate(string $plain): ?PsceqClient
    {
        if (! PsceqApiKey::looksLike($plain)) {
            return null;
        }

        $client = PsceqClient::query()
            ->where('key_prefix', PsceqApiKey::prefixOf($plain))
            ->first();

        if ($client === null || $client->isRevoked()) {
            return null;
        }

        if (! Hash::check($plain, $client->key_hash)) {
            return null;
        }

        $client->forceFill(['last_used_at' => now()])->saveQuietly();

        return $client;
    }
}
