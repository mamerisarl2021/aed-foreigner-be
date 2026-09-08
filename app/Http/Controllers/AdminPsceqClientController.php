<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Admin\ListPsceqClientsRequest;
use App\Http\Requests\Admin\RevokePsceqClientRequest;
use App\Http\Requests\Admin\StorePsceqClientRequest;
use App\Http\Resources\PsceqClientResource;
use App\Models\PsceqClient;
use App\Services\Psceq\PsceqClientAdminService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Illuminate\Http\JsonResponse;

#[Group('Admin')]
final class AdminPsceqClientController extends BaseController
{
    public function __construct(
        private readonly PsceqClientAdminService $clients,
    ) {}

    /**
     * List PSCEQ API clients
     *
     * Returns prefixes and revocation state. Never returns the plaintext key
     * or the stored hash.
     */
    public function index(ListPsceqClientsRequest $request): JsonResponse
    {
        $this->authorize('viewAny', PsceqClient::class);

        $perPage = min((int) ($request->validated('per_page') ?? 15), 100);
        $paginator = $this->clients->list($perPage);
        $paginator->getCollection()->transform(
            fn (PsceqClient $item) => new PsceqClientResource($item)
        );

        return $this->sendResponse('Liste des clients PSCEQ.', $paginator);
    }

    /**
     * Issue a PSCEQ API key
     *
     * Body: `{ nom }`. The plaintext `api_key` is returned once; it cannot be
     * read again. Store it out of band with the prestataire.
     */
    public function store(StorePsceqClientRequest $request): JsonResponse
    {
        $this->authorize('create', PsceqClient::class);

        $actorId = is_string($request->user()?->id) ? $request->user()->id : null;
        $result = $this->clients->create((string) $request->validated('nom'), $actorId);
        if (! $result->success) {
            return $this->respond($result);
        }

        $payload = $result->data;
        if (! is_array($payload)
            || ! ($payload['client'] ?? null) instanceof PsceqClient
            || ! is_string($payload['api_key'] ?? null)
        ) {
            return $this->sendError('Impossible de créer le client PSCEQ.', [], 500);
        }

        $body = new PsceqClientResource($payload['client'])->toArray($request);
        $body['api_key'] = $payload['api_key'];

        return $this->sendResponse($result->message, $body, $result->code);
    }

    /**
     * Revoke a PSCEQ API key
     *
     * Sets `revoked_at`. Subsequent partner calls with that key return 401.
     * Idempotent if already revoked.
     */
    #[PathParameter('id', description: 'PSCEQ client UUID.', type: 'string', format: 'uuid')]
    public function revoke(RevokePsceqClientRequest $request): JsonResponse
    {
        $client = PsceqClient::query()->find((string) $request->validated('id'));
        if ($client === null) {
            return $this->sendError('Client PSCEQ introuvable.', [], 404);
        }

        $this->authorize('revoke', $client);

        $actorId = is_string($request->user()?->id) ? $request->user()->id : null;
        $result = $this->clients->revoke($client->id, $actorId);
        if (! $result->success || ! $result->data instanceof PsceqClient) {
            return $this->respond($result);
        }

        return $this->sendResponse($result->message, new PsceqClientResource($result->data));
    }
}
