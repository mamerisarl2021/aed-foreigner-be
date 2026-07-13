<?php

namespace App\Http\Controllers;

use App\Jobs\RevocatedEmailJob;
use App\Jobs\SendSmsJob;
use App\Models\Revocation;
use App\Services\PKI\TrustedXClientService;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RevocationController extends BaseController
{
    public function __construct(private readonly TrustedXClientService $trustedXClient) {}

    public function index(Request $request)
    {
        try {
            $revocations = Revocation::paginate($request->get('perPage', 9999999999999));

            $flattenedData = $revocations->toArray();
            $data = $flattenedData['data'];
            unset($flattenedData['data']);

            $response = array_merge(['data' => $data], ['pagination' => $flattenedData]);

            return $this->sendPaginatedResponse('Liste des demandes de révocation.', $response);
        } catch (Exception $e) {
            Log::error('Impossible de récupérer les demandes de révocations: '.$e->getMessage());

            return $this->sendError('Impossible de récupérer les demandes de révocations.', null, 500);
        }
    }

    public function mine()
    {
        try {
            $revocations = Revocation::where('user_id', auth()->id())->get();

            return $this->sendResponse('Mes demandes de révocations.', $revocations);
        } catch (Exception $e) {
            Log::error('Fetching revocations failed: '.$e->getMessage());

            return $this->sendError('Fetching revocation failed.', null, 500);
        }
    }

    // Créer un nouveau processus de révocation
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'structure_id' => 'nullable|exists:structures,id',
                'identity_id' => 'required|string|unique:revocations,identity_id',
                'user_id' => 'required|string',
            ]);
            $identityIdToFind = $validated['identity_id'];

            $result = $this->getSignIdentitiesGroupByUserId($validated['user_id']);

            if (isset($result['error'])) {
                // Gestion des erreurs
                dd('Erreur: '.$result['error']);
            } else {
                // Afficher ou utiliser les données retournées
                $group = $this->findSignIdentityGroupByIdentityId($result, $identityIdToFind);
                if ($group == null) {
                    throw new Exception("Aucun groupe trouvé pour l'identity_id fourni.");
                }
            }

            $revocation = Revocation::create([
                'user_id' => Auth::user()->id,
                'structure_id' => isset($validated['structure_id']) ? $validated['structure_id'] : null,
                'identity_id' => $validated['identity_id'],
                'group_id' => $group['id'],
            ]);

            return $this->sendResponse('Processus de révocation créé avec succès', $revocation);
        } catch (Exception $e) {
            return $this->sendError('Erreur lors de la création du processus de révocation', [$e->getMessage()], 500);
        }
    }

    // Mettre à jour le statut d'un processus de révocation spécifique
    public function updateStatus(Request $request, Revocation $revocation)
    {
        try {
            $validated = $request->validate([
                'status' => 'required|in:SENT,REJECTED,TRAITEDBYMANAGER,TRAITEDBYAGENT',
            ]);

            $revocation->update(['status' => $validated['status']]);

            // Si le statut est "TRAITEDBYAGENT", faire les appels API
            if ($validated['status'] === 'TRAITEDBYAGENT') {
                $postData = ['sign_identities_group_id' => $revocation->group_id];
                $tokenResponse = $this->trustedXClient->getToken('urn:safelayer:eidas:sign:identity:manage');
                if ($tokenResponse['status']) {
                    $token = $tokenResponse['token'];
                } else {
                    return ['status' => false, 'message' => 'Nous n\'avons pas pu récupérer le token d\'authentification.'];
                }

                // Appel POST
                $postResponse = Http::withHeaders([
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer '.$token,
                ])->post('https://'.config('trustedx.base_url').'/trustedx-resources/rap/v2/revocation_processes', $postData);

                if ($postResponse->failed()) {
                    throw new Exception('Erreur lors de la création du processus de révocation');
                }

                // Appel DELETE
                $deleteResponse = Http::withHeaders([
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ])->delete('https://'.config('trustedx.base_url').'/trustedx-resources/rap/v2/revocation_processes/'.$revocation->id);

                // if ($deleteResponse->failed()) {
                //     throw new Exception('Erreur lors de la suppression du processus de révocation');
                // }
                $user = $revocation->user;
                if (isset($user) && $user->email != null) {
                    $email = $user->email;
                    RevocatedEmailJob::dispatch($email);
                } else {
                    $phoneNumber = $user->phonenumber;
                    // SendSmsJob::dispatch($phoneNumber, "Votre demande de révocation d'identité numérique a bien été traité pas nos agents accédez à votre espace pour consulter les détails.");
                }
            }

            return $this->sendResponse('Statut du processus de révocation mis à jour avec succès', $revocation);
        } catch (Exception $e) {
            return $this->sendError('Erreur lors de la mise à jour du statut du processus de révocation', [$e->getMessage()], 500);
        }
    }

    // Mettre à jour le statut de plusieurs processus de révocation
    public function updateMultipleStatus(Request $request)
    {
        try {
            $validated = $request->validate([
                'revocation_ids' => 'required|array',
                'revocation_ids.*' => 'exists:revocations,id',
                'status' => 'required|in:SENT,REJECTED,TRAITEDBYMANAGER,TRAITEDBYAGENT',
            ]);

            $revocations = Revocation::whereIn('id', $validated['revocation_ids'])->get();
            $tokenResponse = $this->trustedXClient->getToken('urn:safelayer:eidas:sign:identity:manage');
            if ($tokenResponse['status']) {
                $token = $tokenResponse['token'];
            } else {
                return ['status' => false, 'message' => 'Nous n\'avons pas pu récupérer le token d\'authentification.'];
            }

            foreach ($revocations as $revocation) {
                $revocation->update(['status' => $validated['status']]);

                if ($validated['status'] === 'TRAITEDBYAGENT') {
                    $postData = ['sign_identities_group_id' => $revocation->group_id];

                    // Appel POST
                    $postResponse = Http::withHeaders([
                        'Content-Type' => 'application/json',
                        'Authorization' => 'Bearer '.$token,
                    ])->post('https://'.config('trustedx.base_url').'/trustedx-resources/rap/v2/revocation_processes', $postData);

                    if ($postResponse->failed()) {
                        Log::debug($postResponse->body());
                        throw new Exception('Erreur lors de la création du processus de révocation pour ID '.$revocation->id);
                    }

                    // Appel DELETE
                    $deleteResponse = Http::withHeaders([
                        'Content-Type' => 'application/x-www-form-urlencoded',
                    ])->delete('https://'.config('trustedx.base_url').'/trustedx-resources/rap/v2/revocation_processes/'.$revocation->id);

                    // if ($deleteResponse->failed()) {
                    //     throw new Exception('Erreur lors de la suppression du processus de révocation pour ID ' . $revocation->id);
                    // }
                    $user = $revocation->user;
                    if (isset($user) && $user->email != null) {
                        $email = $user->email;
                        RevocatedEmailJob::dispatch($email);
                    } else {
                        $phoneNumber = $user->phonenumber;
                        // SendSmsJob::dispatch($phoneNumber, "Votre demande de révocation d'identité numérique a bien été traité pas nos agents accédez à votre espace pour consulter les détails.");
                    }
                }
            }

            return $this->sendResponse('Le statut de '.count($validated['revocation_ids']).' processus de révocation a été mis à jour avec succès.');
        } catch (Exception $e) {
            return $this->sendError('Erreur lors de la mise à jour du statut des processus de révocation', [$e], 500);
        }
    }

    public function findSignIdentityGroupByIdentityId(array $responseData, string $identityId)
    {
        foreach ($responseData['sign_identities_groups'] as $group) {
            foreach ($group['sign_identities'] as $identity) {
                if ($identity['id'] === $identityId) {
                    return $group; // Retourne le groupe si l'identity_id correspond
                }
            }
        }

        return null; // Retourne null si aucun groupe avec le identity_id n'est trouvé
    }

    // Supprimer un processus de révocation
    public function destroy(Revocation $revocation)
    {
        try {
            $revocation->delete();

            return $this->sendResponse('Processus de révocation supprimé avec succès');
        } catch (Exception $e) {
            return $this->sendError('Erreur lors de la suppression du processus de révocation', [$e->getMessage()], 500);
        }
    }

    public function getSignIdentitiesGroupByUserId($userId)
    {
        $url = 'https://'.config('trustedx.base_url').'/trustedx-resources/rap/v2/sign_identities_groups?labels=server&user_id='.$userId.'&domain=gob-users';

        try {
            $tokenResponse = $this->trustedXClient->getToken('urn:safelayer:eidas:sign:identity:manage');
            if ($tokenResponse['status']) {
                $token = $tokenResponse['token'];
            } else {
                return ['status' => false, 'message' => 'Nous n\'avons pas pu récupérer le token d\'authentification.'];
            }
            $headers = [
                'Authorization' => 'Bearer '.$token,
            ];

            $client = new Client;

            $response = $client->request('GET', $url, [
                'headers' => $headers,
            ]);

            // Vérifie si la requête est réussie
            if ($response->getStatusCode() === 200) {
                $body = $response->getBody()->getContents();

                // Retourner les données sous forme de tableau
                return json_decode($body, true);
            } else {
                return ['error' => 'Erreur lors de l\'appel API, statut : '.$response->getStatusCode()];
            }
        } catch (RequestException $e) {
            // Gérer les exceptions si la requête échoue
            return ['error' => 'Exception: '.$e->getMessage()];
        }
    }
}
