<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Models\UserSubscription;
use App\Services\PKI\TrustedXClientService;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use phpseclib3\File\X509;

class SigningIdentityService
{
    public function __construct(private readonly TrustedXClientService $trustedXClient) {}

    private string $baseUri = 'https://test-tx-pki.gouv.bj/trustedx-resources/esigp/v1/';

    /**
     * List all signing identities for a user.
     */
    public function getSigningIdentities(string $token, ?string $labels, ?string $userId, ?string $domain): ServiceResult
    {
        $client = new Client;
        $headers = [
            'Authorization' => 'Bearer '.$token,
        ];

        $queryParams = [
            'labels' => $labels,
            'user_id' => $userId,
            'domain' => $domain,
        ];

        try {
            $response = $client->get($this->baseUri.'sign_identities', [
                'headers' => $headers,
                'query' => $queryParams,
            ]);

            $statusCode = $response->getStatusCode();
            $responseBody = json_decode($response->getBody(), true);

            if ($statusCode != 200) {
                return ServiceResult::fail(
                    'Erreur lors de la récupération des identités de signature utilisateur.',
                    $responseBody,
                    $statusCode
                );
            }

            $signIdentities = $responseBody['sign_identities'] ?? [];
            $finalList = [];

            // Fetch and decode each certificate
            foreach ($signIdentities as $certificate) {
                $certResponse = Http::withHeaders($headers)->get($certificate['self']);
                $certBody = json_decode($certResponse->body(), true);
                if (array_key_exists('certificate', $certBody['identity']['details'])) {
                    $certificate['certData'] = $this->getCertData($certBody['identity']['details']['certificate']) ?? null;
                }
                $finalList[] = $certificate;
            }

            return ServiceResult::ok(
                'Identités de signature utilisateur récupérées avec succès.',
                $finalList
            );
        } catch (RequestException $e) {
            return ServiceResult::fail(
                'Erreur lors de la récupération des identités de signature utilisateur.',
                ['error' => $e->getMessage()],
                $e->getCode() ?: 500
            );
        }
    }

    /**
     * Get details of a single signing identity.
     */
    public function getSigningIdentity(string $token, string $identityId): ServiceResult
    {
        $client = new Client;
        $headers = [
            'Authorization' => 'Bearer '.$token,
        ];

        try {
            $response = $client->get($this->baseUri.'sign_identities/'.$identityId, [
                'headers' => $headers,
            ]);

            if ($response->getStatusCode() == 200) {
                return ServiceResult::ok(
                    'Informations sur l\'identité de signature récupérées avec succès.',
                    json_decode($response->getBody(), true)
                );
            }

            return ServiceResult::fail(
                'Erreur lors de la récupération des informations sur l\'identité de signature.',
                json_decode($response->getBody(), true),
                $response->getStatusCode()
            );
        } catch (RequestException $e) {
            return ServiceResult::fail(
                'Erreur lors de la récupération des informations sur l\'identité de signature.',
                ['error' => $e->getMessage()],
                $e->getCode() ?: 500
            );
        }
    }

    /**
     * Extract certificate validity data from a PEM cert.
     */
    public function getCertData(string $cert): ?array
    {
        $x509 = new X509;
        $decodedCert = $x509->loadX509($cert);

        return $decodedCert['tbsCertificate']['validity'];
    }

    /**
     * Update the status of a signing identity.
     */
    public function updateSigningIdentityStatus(string $identityId, string $value, string $reason): ServiceResult
    {
        $tokenResponse = $this->trustedXClient->getToken('urn:safelayer:eidas:sign:identity:manage');
        if (! $tokenResponse['status']) {
            return ServiceResult::fail($tokenResponse['message'], null, 401);
        }

        $token = $tokenResponse['token'];
        $client = new Client;
        $headers = [
            'Authorization' => 'Bearer '.$token,
            'Content-Type' => 'application/json',
        ];
        $body = json_encode([
            'value' => $value,
            'reason' => $reason,
        ]);

        try {
            $response = $client->put($this->baseUri.'sign_identities/'.$identityId.'/status', [
                'headers' => $headers,
                'body' => $body,
            ]);

            if ($response->getStatusCode() == 204) {
                return ServiceResult::ok(
                    'Statut de l\'identité de signature mis à jour avec succès.',
                    json_decode($response->getBody(), true)
                );
            }

            return ServiceResult::fail(
                'Erreur lors de la mise à jour du statut de l\'identité de signature.',
                json_decode($response->getBody(), true),
                $response->getStatusCode()
            );
        } catch (RequestException $e) {
            return ServiceResult::fail(
                'Erreur lors de la mise à jour du statut de l\'identité de signature.',
                ['error' => $e->getMessage()],
                $e->getCode() ?: 500
            );
        }
    }

    /**
     * Delete a signing identity.
     */
    public function deleteSigningIdentity(string $identityId): ServiceResult
    {
        $tokenResponse = $this->trustedXClient->getToken('urn:safelayer:eidas:sign:identity:manage');
        if (! $tokenResponse['status']) {
            return ServiceResult::fail($tokenResponse['message'], null, 401);
        }

        $token = $tokenResponse['token'];
        $client = new Client;
        $headers = [
            'Content-Type' => 'application/json;charset=UTF-8',
            'Authorization' => 'Bearer '.$token,
        ];
        $url = $this->baseUri.'sign_identities/'.$identityId;
        $request = new GuzzleRequest('DELETE', $url, $headers, '');

        try {
            $response = $client->sendAsync($request)->wait();

            return ServiceResult::ok(
                'Identité de signature supprimée avec succès.',
                json_decode($response->getBody(), true)
            );
        } catch (RequestException $e) {
            return ServiceResult::fail(
                'Erreur lors de la suppression de l\'identité de signature.',
                ['error' => $e->getMessage()],
                $e->getCode() ?: 500
            );
        }
    }

    /**
     * Provision a new signing identity (certificate issuance).
     */
    public function provisionSignature(string $code, string $type, int $subscriptionId): ServiceResult
    {
        try {
            // Vérification du statut de la souscription si employé
            if (
                $type === 'employee' &&
                UserSubscription::where('id', $subscriptionId)
                    ->where('status', 'TRAITEDBYMANAGER')
                    ->where('type', 'EMPLOYEE')
                    ->count() < 1
            ) {
                return ServiceResult::fail(
                    "Votre manager n'a pas encore approuvé votre demande de certificat sur serveur. Patientez encore un instant.",
                    null,
                    400
                );
            }

            // Mise à jour du statut si citoyen
            if ($type === 'citizen') {
                $subscription = UserSubscription::find($subscriptionId);
                if ($subscription) {
                    $subscription->update(['status' => 'TRAITEDBYSYSTEM']);
                }
            }

            // Vérification que l'utilisateur est bien ACTIF
            $sub = UserSubscription::find($subscriptionId);
            if ($sub) {
                $user = User::find($sub->user_id);
                if ($user && $user->status !== 'ACTIVE') {
                    return ServiceResult::fail(
                        "Votre compte utilisateur n'est pas encore actif. Veuillez patienter ou contacter le support.",
                        null,
                        403
                    );
                }
            }

            // Obtenir le token d'accès
            $accessTokenResponse = $type === 'employee'
                ? $this->getAccessToken($code, $subscriptionId)
                : $this->getAdminAccessToken($code, $subscriptionId);

            if (! $accessTokenResponse['status']) {
                return ServiceResult::fail(
                    "Impossible d'obtenir le token d'accès.",
                    $accessTokenResponse,
                    400
                );
            }

            $accessToken = $accessTokenResponse['data']['access_token'];

            // Récupérer les données utilisateur
            $userData = $this->getUserData($accessToken);

            // Lancer le processus de provision
            $identityData = $this->createIssuanceProcess($accessToken, $userData, $type);

            // Gestion en fonction du statut renvoyé
            $status = $identityData['response']['status']['value'] ?? null;

            $message = match ($status) {
                'pending' => 'Votre demande est en attente de traitement. Réessayez plus tard.',
                'in_progress' => 'Votre demande est en cours de traitement. Veuillez patienter.',
                'finished' => 'Identité et certificat générés avec succès.',
                default => null,
            };

            if ($message === null) {
                return ServiceResult::fail(
                    "Statut inconnu ou erreur lors du processus d\u{2019}émission.",
                    $identityData,
                    500
                );
            }

            return ServiceResult::ok($message, $identityData);

        } catch (HttpClientException $e) {
            Log::error('Erreur HttpClient : '.$e->getMessage(), ['trace' => $e->getTraceAsString()]);

            return ServiceResult::fail(
                'Une erreur est survenue lors de votre tentative de création de certificat sur serveur.',
                $e->getMessage(),
                500
            );
        } catch (Exception $e) {
            Log::error('Erreur inattendue : '.$e->getMessage(), ['trace' => $e->getTraceAsString()]);

            return ServiceResult::fail(
                'Erreur interne. Veuillez réessayer plus tard.',
                $e->getMessage(),
                500
            );
        }
    }

    private function getAccessToken(string $authorizationCode, int $processId): array
    {
        try {
            $redirectUri = config('app.frontend_url').'/bridge-page?type=employee_'.$processId;
            $response = Http::withOptions([
                'verify' => false,
            ])->withBasicAuth((string) config('trustedx.client_id'), (string) config('trustedx.client_secret'))->post('https://'.config('trustedx.base_url').'/trustedx-authserver/oauth/'.config('trustedx.clients_logged_as')."/token?grant_type=authorization_code&code=$authorizationCode&redirect_uri=$redirectUri");

            if (isset($response->json()['error'])) {
                return [
                    'status' => false,
                    'data' => $response->json(),
                    'message' => 'Il semblerait que le code fournis soit invalide ou expiré.',
                ];
            }

            return [
                'status' => true,
                'data' => $response->json(),
                'message' => "Voici votre code d'authentification.",
            ];
        } catch (HttpClientException $e) {
            Log::error($e->getMessage(), ['error' => json_encode($e)]);

            return [
                'status' => false,
                'message' => 'Il semblerait que le code fournis soit invalide ou expiré.',
            ];
        }
    }

    private function getAdminAccessToken(string $authorizationCode, int $processId): array
    {
        $redirectUri = config('app.frontend_url').'/bridge-page?type=citizen_'.$processId;

        try {
            $response = Http::withOptions([
                'verify' => false,
            ])->withBasicAuth((string) config('trustedx.client_id'), (string) config('trustedx.client_secret'))->post('https://'.config('trustedx.base_url').'/trustedx-authserver/oauth/'.config('trustedx.admins_logged_as')."/token?grant_type=authorization_code&code=$authorizationCode&redirect_uri=$redirectUri");

            if (isset($response->json()['error'])) {
                return [
                    'status' => false,
                    'data' => $response->json(),
                    'message' => 'Il semblerait que le code fournis soit invalide ou expiré.',
                ];
            }

            return [
                'status' => true,
                'data' => $response->json(),
                'message' => "Voici votre code d'authentification.",
            ];
        } catch (HttpClientException $e) {
            Log::error($e->getMessage(), ['error' => json_encode($e)]);

            return [
                'status' => false,
                'message' => 'Il semblerait que le code fournis soit invalide ou expiré.',
            ];
        }
    }

    private function getUserData(string $accessToken): array
    {
        $response = Http::withOptions([
            'verify' => false,
        ])->withToken($accessToken)->get('https://'.config('trustedx.base_url').'/trustedx-resources/openid/v1/users/me');

        return json_decode($response->getBody(), true);
    }

    private function createIssuanceProcess(string $accessToken, array $userData, string $type): array
    {
        $url = 'https://'.config('trustedx.base_url').'/trustedx-resources/rap/v2/issuance_processes';

        $payload = [
            'sign_identities_group_labels' => ['gob', 'server', $type],
            'registration_parameters' => [
                'notification_email' => 'collabone@qualitycorporate.com',
                'user' => $userData,
            ],
        ];

        try {
            Log::info('📤 Envoi création issuance process', [
                'url' => $url,
                'payload' => $payload,
            ]);

            $response = Http::withToken($accessToken)
                ->timeout(30)
                ->acceptJson()
                ->post($url, $payload);

            if ($response->failed()) {
                Log::error('❌ Erreur à la création du process', ['body' => $response->body()]);
                throw new Exception('Erreur lors de la création du process: '.$response->body());
            }

            $responseData = $response->json();

            if (empty($responseData['sign_identities_group']['url'])) {
                throw new Exception("URL du groupe d'identité manquante");
            }

            Log::info("📥 Récupération du groupe d'identité", [
                'url' => $responseData['sign_identities_group']['url'],
            ]);

            $groupResponse = Http::withToken($accessToken)
                ->timeout(15)
                ->get($responseData['sign_identities_group']['url']);

            if ($groupResponse->failed()) {
                Log::error('❌ Erreur lors de la récupération du groupe', ['body' => $groupResponse->body()]);
                throw new Exception('Erreur groupe identité: '.$groupResponse->body());
            }

            $groupBody = $groupResponse->json();

            if (empty($groupBody['sign_identities'][0]['url'])) {
                throw new Exception("Pas d'URL d'identité trouvée dans le groupe");
            }

            Log::info("📥 Récupération de l\u{2019}identité", [
                'url' => $groupBody['sign_identities'][0]['url'],
            ]);

            $certResponse = Http::withToken($accessToken)
                ->timeout(15)
                ->get($groupBody['sign_identities'][0]['url']);

            if ($certResponse->failed()) {
                Log::error('❌ Erreur lors de la récupération du certificat', ['body' => $certResponse->body()]);
                throw new Exception('Erreur certificat: '.$certResponse->body());
            }

            $certBody = $certResponse->json();

            $certificate = [];
            if (array_key_exists('certificate', $certBody['identity']['details'])) {
                $certificate['validity'] = $this->getCertData($certBody['identity']['details']['certificate']) ?? null;
            }

            $certificate['details'] = $certBody['identity']['details'] ?? [];

            if (($responseData['status']['value'] ?? null) === 'finished') {
                Log::info('🗑 Suppression du process terminé', ['id' => $responseData['id']]);
                $this->deleteIssuanceProcess($responseData['id'], $accessToken);
            }

            return ['response' => $responseData, 'cert' => $certificate];
        } catch (Exception $e) {
            Log::error('⚠️ Exception dans createIssuanceProcess', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    private function deleteIssuanceProcess(string $processId, string $accessToken): ?array
    {
        $client = new Client;
        $url = 'https://'.config('trustedx.base_url')."/trustedx-resources/rap/v2/issuance_processes/{$processId}";

        try {
            $response = $client->delete($url, [
                'headers' => [
                    'Authorization' => 'Bearer '.$accessToken,
                    'Content-Type' => 'application/json',
                ],
            ]);

            return json_decode($response->getBody(), true);
        } catch (RequestException $e) {
            $errorBody = '';
            if ($e->hasResponse()) {
                $errorResponse = $e->getResponse();
                $errorBody = $errorResponse->getBody()->getContents();
                Log::error($e->getMessage(), ['trace' => $errorBody]);
            }
            Log::error($e->getMessage(), ['trace' => $errorBody]);

            throw new Exception('Erreur lors de la suppression du processus de certification: '.$e->getMessage());
        }
    }
}
