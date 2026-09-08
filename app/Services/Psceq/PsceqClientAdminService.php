<?php

declare(strict_types=1);

namespace App\Services\Psceq;

use App\Enums\ActivityLogAction;
use App\Models\PsceqClient;
use App\Services\ActivityLog\ActivityLogService;
use App\Services\ServiceResult;
use App\Support\PsceqApiKey;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Throwable;

final class PsceqClientAdminService
{
    public function __construct(
        private readonly ActivityLogService $activityLog,
    ) {}

    /**
     * @return LengthAwarePaginator<int, PsceqClient>
     */
    public function list(int $perPage): LengthAwarePaginator
    {
        return PsceqClient::query()
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    public function create(string $name, ?string $actorUserId): ServiceResult
    {
        try {
            $plain = null;
            $client = null;

            for ($attempt = 0; $attempt < 8; $attempt++) {
                $candidate = PsceqApiKey::generate();
                $prefix = PsceqApiKey::prefixOf($candidate);
                if (PsceqClient::query()->where('key_prefix', $prefix)->exists()) {
                    continue;
                }

                $plain = $candidate;
                $client = PsceqClient::query()->create([
                    'name' => $name,
                    'key_prefix' => $prefix,
                    'key_hash' => Hash::make($candidate),
                    'created_by_user_id' => $actorUserId,
                ]);
                break;
            }

            if ($client === null || $plain === null) {
                return ServiceResult::fail('Impossible d\'émettre une clé API unique.', null, 500);
            }

            $this->activityLog->record(
                ActivityLogAction::PsceqClientCree,
                sprintf('Clé API PSCEQ émise pour %s.', $client->name),
                $actorUserId,
                null,
                [
                    'psceq_client_id' => $client->id,
                    'key_prefix' => $client->key_prefix,
                ],
            );

            return ServiceResult::ok('Clé API PSCEQ créée.', [
                'client' => $client,
                'api_key' => $plain,
            ], 201);
        } catch (Throwable $e) {
            Log::error('Failed to create PSCEQ client', ['error' => $e->getMessage()]);

            return ServiceResult::fail('Impossible de créer le client PSCEQ.', null, 500);
        }
    }

    public function revoke(string $id, ?string $actorUserId): ServiceResult
    {
        $client = PsceqClient::query()->find($id);
        if ($client === null) {
            return ServiceResult::fail('Client PSCEQ introuvable.', null, 404);
        }

        if (! $client->isRevoked()) {
            $client->revoked_at = now();
            $client->save();

            $this->activityLog->record(
                ActivityLogAction::PsceqClientRevoque,
                sprintf('Clé API PSCEQ révoquée pour %s.', $client->name),
                $actorUserId,
                null,
                [
                    'psceq_client_id' => $client->id,
                    'key_prefix' => $client->key_prefix,
                ],
            );
        }

        return ServiceResult::ok('Clé API PSCEQ révoquée.', $client);
    }
}
