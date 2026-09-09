<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Admin\DeletePsceqClientRequest;
use App\Http\Requests\Admin\ListPsceqClientsRequest;
use App\Http\Requests\Admin\RegeneratePsceqClientRequest;
use App\Http\Requests\Admin\RevokePsceqClientRequest;
use App\Http\Requests\Admin\ShowPsceqClientRequest;
use App\Http\Requests\Admin\StorePsceqClientRequest;
use App\Http\Requests\Admin\UpdatePsceqClientRequest;
use App\Http\Resources\PsceqClientHistoriqueResource;
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
     * PSCEQ client's event history
     *
     * Last 100 events touching this client (creation, revocation, key
     * regeneration...), most recent first.
     */
    #[PathParameter('id', description: 'PSCEQ client UUID.', type: 'string', format: 'uuid')]
    public function historique(ShowPsceqClientRequest $request): JsonResponse
    {
        $client = PsceqClient::query()->find((string) $request->validated('id'));
        if ($client === null) {
            return $this->sendError('Client PSCEQ introuvable.', [], 404);
        }

        $this->authorize('view', $client);

        $evenements = $this->clients->historique($client->id);

        return $this->sendResponse('Historique du client PSCEQ.', PsceqClientHistoriqueResource::collection($evenements));
    }

    #[PathParameter('id', description: 'PSCEQ client UUID.', type: 'string', format: 'uuid')]
    public function show(ShowPsceqClientRequest $request): JsonResponse
    {
        $client = PsceqClient::query()->with('createdBy')->find((string) $request->validated('id'));
        if ($client === null) {
            return $this->sendError('Client PSCEQ introuvable.', [], 404);
        }

        $this->authorize('view', $client);

        return $this->sendResponse('Client PSCEQ.', new PsceqClientResource($client));
    }

    /**
     * Regenerate a PSCEQ API key
     *
     * Invalidates the previous key. The plaintext `api_key` is returned once.
     */
    #[PathParameter('id', description: 'PSCEQ client UUID.', type: 'string', format: 'uuid')]
    public function regenerate(RegeneratePsceqClientRequest $request): JsonResponse
    {
        $client = PsceqClient::query()->find((string) $request->validated('id'));
        if ($client === null) {
            return $this->sendError('Client PSCEQ introuvable.', [], 404);
        }

        $this->authorize('update', $client);

        $actorId = is_string($request->user()?->id) ? $request->user()->id : null;
        $result = $this->clients->regenerate($client->id, $actorId);
        if (! $result->success) {
            return $this->respond($result);
        }

        $payload = $result->data;
        if (! is_array($payload)
            || ! ($payload['client'] ?? null) instanceof PsceqClient
            || ! is_string($payload['api_key'] ?? null)
        ) {
            return $this->sendError('Impossible de régénérer la clé API.', [], 500);
        }

        $body = new PsceqClientResource($payload['client'])->toArray($request);
        $body['api_key'] = $payload['api_key'];

        return $this->sendResponse($result->message, $body);
    }

    /**
     * Delete a PSCEQ client
     */
    #[PathParameter('id', description: 'PSCEQ client UUID.', type: 'string', format: 'uuid')]
    public function destroy(DeletePsceqClientRequest $request): JsonResponse
    {
        $client = PsceqClient::query()->find((string) $request->validated('id'));
        if ($client === null) {
            return $this->sendError('Client PSCEQ introuvable.', [], 404);
        }

        $this->authorize('delete', $client);

        $actorId = is_string($request->user()?->id) ? $request->user()->id : null;
        $result = $this->clients->delete($client->id, $actorId);
        if (! $result->success) {
            return $this->respond($result);
        }

        return $this->sendResponse($result->message, null);
    }

    /**
     * Issue a PSCEQ API key
     *
     * Body: profil complet du prestataire. The plaintext `api_key` is
     * returned once; it cannot be read again. Store it out of band with the
     * prestataire.
     */
    public function store(StorePsceqClientRequest $request): JsonResponse
    {
        $this->authorize('create', PsceqClient::class);

        $actorId = is_string($request->user()?->id) ? $request->user()->id : null;
        $result = $this->clients->create($request->validated(), $actorId);
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
     * Update a PSCEQ client's profile
     *
     * Profile fields only — does not touch the API key or revocation state.
     */
    #[PathParameter('id', description: 'PSCEQ client UUID.', type: 'string', format: 'uuid')]
    public function update(UpdatePsceqClientRequest $request): JsonResponse
    {
        $client = PsceqClient::query()->find((string) $request->validated('id'));
        if ($client === null) {
            return $this->sendError('Client PSCEQ introuvable.', [], 404);
        }

        $this->authorize('update', $client);

        $actorId = is_string($request->user()?->id) ? $request->user()->id : null;
        $result = $this->clients->update(
            $client->id,
            $request->safe()->except('id'),
            $actorId,
        );
        if (! $result->success || ! $result->data instanceof PsceqClient) {
            return $this->respond($result);
        }

        return $this->sendResponse($result->message, new PsceqClientResource($result->data));
    }

    /**
     * Revoke a PSCEQ API key
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
