<?php

declare(strict_types=1);

namespace App\Services\Psceq;

use App\Enums\ActivityLogAction;
use App\Models\ActivityLog;
use App\Models\PsceqClient;
use App\Services\ActivityLog\ActivityLogService;
use App\Services\ServiceResult;
use App\Support\PsceqApiKey;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

final class PsceqClientAdminService
{
    /** @var list<string> */
    private const PROFILE_FIELDS = [
        'raison_sociale',
        'rccm',
        'pays',
        'adresse_siege',
        'site_web',
        'email',
        'telephone',
        'point_focal_nom',
        'point_focal_prenom',
        'point_focal_fonction',
        'point_focal_email',
        'point_focal_telephone',
    ];

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

    /**
     * @param  array<string, mixed>  $data  Validé depuis StorePsceqClientRequest ('nom' + champs de profil).
     */
    public function create(array $data, ?string $actorUserId): ServiceResult
    {
        try {
            $plain = null;
            $client = null;
            $profile = array_intersect_key($data, array_flip(self::PROFILE_FIELDS));

            for ($attempt = 0; $attempt < 8; $attempt++) {
                $candidate = PsceqApiKey::generate();
                $prefix = PsceqApiKey::prefixOf($candidate);
                if (PsceqClient::query()->where('key_prefix', $prefix)->exists()) {
                    continue;
                }

                $plain = $candidate;
                $client = PsceqClient::query()->create([
                    ...$profile,
                    'name' => $data['nom'],
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

    /**
     * @param  array<string, mixed>  $data  Validé depuis UpdatePsceqClientRequest ('nom' + champs de profil).
     */
    public function update(string $id, array $data, ?string $actorUserId): ServiceResult
    {
        $client = PsceqClient::query()->find($id);
        if ($client === null) {
            return ServiceResult::fail('Client PSCEQ introuvable.', null, 404);
        }

        try {
            $profile = array_intersect_key($data, array_flip(self::PROFILE_FIELDS));
            $client->fill([
                ...$profile,
                'name' => $data['nom'],
            ]);
            $client->save();

            $this->activityLog->record(
                ActivityLogAction::PsceqClientModifie,
                sprintf('Profil PSCEQ modifié pour %s.', $client->name),
                $actorUserId,
                null,
                ['psceq_client_id' => $client->id],
            );

            return ServiceResult::ok('Client PSCEQ modifié.', $client);
        } catch (Throwable $e) {
            Log::error('Failed to update PSCEQ client', ['error' => $e->getMessage()]);

            return ServiceResult::fail('Impossible de modifier le client PSCEQ.', null, 500);
        }
    }

    public function regenerate(string $id, ?string $actorUserId): ServiceResult
    {
        $client = PsceqClient::query()->find($id);
        if ($client === null) {
            return ServiceResult::fail('Client PSCEQ introuvable.', null, 404);
        }

        try {
            $plain = null;

            for ($attempt = 0; $attempt < 8; $attempt++) {
                $candidate = PsceqApiKey::generate();
                $prefix = PsceqApiKey::prefixOf($candidate);
                if (PsceqClient::query()->where('key_prefix', $prefix)->where('id', '!=', $client->id)->exists()) {
                    continue;
                }

                $plain = $candidate;
                $client->key_prefix = $prefix;
                $client->key_hash = Hash::make($candidate);
                $client->save();
                break;
            }

            if ($plain === null) {
                return ServiceResult::fail('Impossible d\'émettre une clé API unique.', null, 500);
            }

            $this->activityLog->record(
                ActivityLogAction::PsceqClientRegenere,
                sprintf('Clé API PSCEQ régénérée pour %s.', $client->name),
                $actorUserId,
                null,
                ['psceq_client_id' => $client->id, 'key_prefix' => $client->key_prefix],
            );

            return ServiceResult::ok('Clé API PSCEQ régénérée.', [
                'client' => $client,
                'api_key' => $plain,
            ]);
        } catch (Throwable $e) {
            Log::error('Failed to regenerate PSCEQ client key', ['error' => $e->getMessage()]);

            return ServiceResult::fail('Impossible de régénérer la clé API.', null, 500);
        }
    }

    public function delete(string $id, ?string $actorUserId): ServiceResult
    {
        $client = PsceqClient::query()->find($id);
        if ($client === null) {
            return ServiceResult::fail('Client PSCEQ introuvable.', null, 404);
        }

        try {
            $nom = $client->name;
            $client->delete();

            $this->activityLog->record(
                ActivityLogAction::PsceqClientSupprime,
                sprintf('Client PSCEQ supprimé : %s.', $nom),
                $actorUserId,
                null,
                ['psceq_client_id' => $id],
            );

            return ServiceResult::ok('Client PSCEQ supprimé.', null);
        } catch (Throwable $e) {
            Log::error('Failed to delete PSCEQ client', ['error' => $e->getMessage()]);

            return ServiceResult::fail('Impossible de supprimer le client PSCEQ.', null, 500);
        }
    }

    /**
     * @return Collection<int, ActivityLog>
     */
    public function historique(string $id): Collection
    {
        return ActivityLog::query()
            ->whereJsonContains('metadata->psceq_client_id', $id)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();
    }

    public function revoke(string $id, ?string $actorUserId): ServiceResult
    {
        $client = PsceqClient::query()->find($id);
        if ($client === null) {
            return ServiceResult::fail('Client PSCEQ introuvable.', null, 404);
        }

        if ($client->isRevoked()) {
            $client->revoked_at = null;
            $client->save();

            $this->activityLog->record(
                ActivityLogAction::PsceqClientReactive,
                sprintf('Clé API PSCEQ réactivée pour %s.', $client->name),
                $actorUserId,
                null,
                [
                    'psceq_client_id' => $client->id,
                    'key_prefix' => $client->key_prefix,
                ],
            );

            return ServiceResult::ok('Clé API PSCEQ réactivée.', $client);
        }

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

        return ServiceResult::ok('Clé API PSCEQ révoquée.', $client);
    }
}
