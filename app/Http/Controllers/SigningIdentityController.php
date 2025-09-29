<?php

namespace App\Http\Controllers;

use App\Models\UserSubscription;
use App\Traits\AuthTrait;
use Exception;
use Illuminate\Http\Request;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use phpseclib3\File\X509 as X509;

class SigningIdentityController extends BaseController
{
    use AuthTrait;
    private $baseUri = 'https://test-tx-pki.gouv.bj/trustedx-resources/esigp/v1/';

    public function getSigningIdentities(Request $request)
    {
        $client = new Client();
        $token = $request->input('token');
        $headers = [
            'Authorization' => 'Bearer ' . $token
        ];

        $queryParams = [
            'labels' => $request->input('labels'),
            'user_id' => $request->input('user_id'),
            'domain' => $request->input('domain')
        ];

        try {
            // Initial request to get the signing identities
            $response = $client->get($this->baseUri . 'sign_identities', [
                'headers' => $headers,
                'query' => $queryParams
            ]);

            $statusCode = $response->getStatusCode();
            $responseBody = json_decode($response->getBody(), true);

            if ($statusCode != 200) {
                return $this->sendError(
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
                if (array_key_exists('certificate', $certBody['identity']['details']))
                    $certificate['certData'] = $this->getCertData($certBody['identity']['details']['certificate']) ?? null;
                $finalList[] = $certificate;
            }

            return $this->sendResponse(
                'Identités de signature utilisateur récupérées avec succès.',
                $finalList
            );
        } catch (RequestException $e) {
            return $this->sendError(
                'Erreur lors de la récupération des identités de signature utilisateur.',
                ['error' => $e->getMessage()],
                $e->getCode()
            );
        }
    }

    public function getSigningIdentity(Request $request, $identityId)
    {
        $client = new Client();
        $headers = [
            'Authorization' => 'Bearer ' . $request->input('token')
        ];

        try {
            $response = $client->get($this->baseUri . 'sign_identities/' . $identityId, [
                'headers' => $headers
            ]);

            if ($response->getStatusCode() == 200) {
                return $this->sendResponse('Informations sur l\'identité de signature récupérées avec succès.', json_decode($response->getBody(), true));
            } else {
                return $this->sendError('Erreur lors de la récupération des informations sur l\'identité de signature.', json_decode($response->getBody(), true), $response->getStatusCode());
            }
        } catch (RequestException $e) {
            return $this->sendError('Erreur lors de la récupération des informations sur l\'identité de signature.', ['error' => $e->getMessage()], $e->getCode());
        }
    }

    /**
     * @param $champs
     * @return array
     */
    function getCertData($cert)
    {
        $x509 = new X509();
        $decodedCert = $x509->loadX509($cert);
        // dd($decodedCert['tbsCertificate']['validity']);
        return $decodedCert['tbsCertificate']['validity'];
    }

    public function updateSigningIdentityStatus(Request $request, $identityId)
    {
        $token_response = $this->getToken('urn:safelayer:eidas:sign:identity:manage');
        if (!$token_response['status']) {
            return $this->sendError($token_response['message'], null, 401);
        }
        $token = $token_response['token'];
        $client = new Client();
        $headers = [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'application/json'
        ];
        $body = json_encode([
            'value' => $request->input('value'),
            'reason' => $request->input('reason')
        ]);

        try {
            $response = $client->put($this->baseUri . 'sign_identities/' . $identityId . '/status', [
                'headers' => $headers,
                'body' => $body
            ]);

            if ($response->getStatusCode() == 204) {
                return $this->sendResponse('Statut de l\'identité de signature mis à jour avec succès.', json_decode($response->getBody(), true));
            } else {
                return $this->sendError('Erreur lors de la mise à jour du statut de l\'identité de signature.', json_decode($response->getBody(), true), $response->getStatusCode());
            }
        } catch (RequestException $e) {
            return $this->sendError('Erreur lors de la mise à jour du statut de l\'identité de signature.', ['error' => $e->getMessage()], $e->getCode());
        }
    }

    public function deleteSigningIdentity($identityId)
    {
        $token_response = $this->getToken('urn:safelayer:eidas:sign:identity:manage');
        if (!$token_response['status']) {
            return $this->sendError($token_response['message'], null, 401);
        }
        $token = $token_response['token'];
        $client = new Client();
        $headers = [
            'Content-Type' => 'application/json;charset=UTF-8',
            'Authorization' => 'Bearer ' . $token
        ];
        $body = '';
        $url = $this->baseUri . 'sign_identities/' . $identityId;
        $request = new GuzzleRequest('DELETE', $url, $headers, $body);

        try {
            $response = $client->sendAsync($request)->wait();
            return $this->sendResponse('Identité de signature supprimée avec succès.', json_decode($response->getBody(), true));
        } catch (RequestException $e) {
            return $this->sendError('Erreur lors de la suppression de l\'identité de signature.', ['error' => $e->getMessage()], $e->getCode());
        }
    }

    // public function provisionSignature(Request $request)
    // {
    //     try {

    //         $validatedData = $request->validate([
    //             'code' => 'required|string|max:255',
    //             'type' => 'required|string|max:255',
    //             'subscription_id' => 'required|integer',
    //         ]);
    //         // Étape 2 : Obtenir le jeton d'accès
    //         $accessTokenResponse = $validatedData['type'] == 'employee' ? $this->getAccessToken($validatedData['code'], $validatedData['subscription_id']) : $this->getAdminAccessToken($validatedData['code'], $validatedData['subscription_id']);
    //         if (
    //             $validatedData['type'] == 'employee' &&
    //             UserSubscription::where('id', $validatedData['subscription_id'])
    //             ->where('status', 'TRAITEDBYMANAGER')
    //             ->where('type', 'EMPLOYEE')
    //             ->count() < 1
    //         ) {
    //             return $this->sendError('Votre manager n\'a pas approuvé votre demande ce certificat sur serveur patientez encore un instant.', null, 403);
    //         }

    //         if ($validatedData['type'] == 'citizen') {
    //             $subscription = UserSubscription::where('id', $validatedData['subscription_id'])->first();

    //             if ($subscription) {
    //                 $subscription->update(['status' => 'TRAITEDBYSYSTEM']);
    //             }
    //         }


    //         if ($accessTokenResponse['status']) {
    //             $access_token = $accessTokenResponse['data']['access_token'];
    //         } else {
    //             return [
    //                 'status' => false,
    //                 'data' => $accessTokenResponse,
    //                 'message' => $accessTokenResponse['message']
    //             ];
    //         }


    //         // Étape 3 : Obtenir les données d'identité de l'utilisateur
    //         $userData = $this->getUserData($access_token);

    //         // Étape 4 : Créer les clés de signature, la demande de certification et l'identité de signature
    //         $identityData = $this->createIssuanceProcess($access_token, $userData, $validatedData['type']);

    //         return $this->sendResponse('Identity provisioned successfully', $identityData);
    //     } catch (HttpClientException $e) {
    //         Log::error($e->getMessage(), ['error' => json_encode($e)]);
    //         return [
    //             'status' => false,
    //             'message' => "Il qu'une erreur soit survenue lors de votre tentative de création de certificat sur serveur."
    //         ];
    //     }
    // }

    public function provisionSignature(Request $request)
    {
        try {
            // ✅ Étape 1 : Validation
            $validatedData = $request->validate([
                'code' => 'required|string|max:255',
                'type' => 'required|string|max:255',
                'subscription_id' => 'required|integer',
            ]);

            // ✅ Étape 2 : Vérification du statut de la souscription si employé
            if (
                $validatedData['type'] === 'employee' &&
                UserSubscription::where('id', $validatedData['subscription_id'])
                ->where('status', 'TRAITEDBYMANAGER')
                ->where('type', 'EMPLOYEE')
                ->count() < 1
            ) {
                return $this->sendError(
                    "Votre manager n'a pas encore approuvé votre demande de certificat sur serveur. Patientez encore un instant.",
                    null,
                    400
                );
            }

            // ✅ Étape 3 : Mise à jour du statut si citoyen
            if ($validatedData['type'] === 'citizen') {
                $subscription = UserSubscription::find($validatedData['subscription_id']);
                if ($subscription) {
                    $subscription->update(['status' => 'TRAITEDBYSYSTEM']);
                }
            }

            // ✅ Étape 4 : Obtenir le token d'accès
            $accessTokenResponse = $validatedData['type'] === 'employee'
                ? $this->getAccessToken($validatedData['code'], $validatedData['subscription_id'])
                : $this->getAdminAccessToken($validatedData['code'], $validatedData['subscription_id']);

            if (!$accessTokenResponse['status']) {
                return $this->sendError(
                    "Impossible d'obtenir le token d'accès.",
                    $accessTokenResponse,
                    400
                );
            }

            $access_token = $accessTokenResponse['data']['access_token'];

            // ✅ Étape 5 : Récupérer les données utilisateur
            $userData = $this->getUserData($access_token);

            // ✅ Étape 6 : Lancer le processus de provision
            $identityData = $this->createIssuanceProcess($access_token, $userData, $validatedData['type']);

            // ✅ Étape 7 : Gestion en fonction du statut renvoyé
            $status = $identityData['response']['status']['value'] ?? null;

            switch ($status) {
                case 'pending':
                    return $this->sendResponse(
                        "Votre demande est en attente de traitement. Réessayez plus tard.",
                        $identityData
                    );

                case 'in_progress':
                    return $this->sendResponse(
                        "Votre demande est en cours de traitement. Veuillez patienter.",
                        $identityData
                    );

                case 'finished':
                    return $this->sendResponse(
                        "Identité et certificat générés avec succès.",
                        $identityData
                    );

                default:
                    return $this->sendError(
                        "Statut inconnu ou erreur lors du processus d’émission.",
                        $identityData,
                        500
                    );
            }
        } catch (HttpClientException $e) {
            Log::error("Erreur HttpClient : " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return $this->sendError(
                "Une erreur est survenue lors de votre tentative de création de certificat sur serveur.",
                $e->getMessage(),
                500
            );
        } catch (\Exception $e) {
            Log::error("Erreur inattendue : " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return $this->sendError(
                "Erreur interne. Veuillez réessayer plus tard.",
                $e->getMessage(),
                500
            );
        }
    }


    private function getAccessToken($authorizationCode, $processId)
    {
        try {
            $redirect_uri = env('FRONTEND_URL') . '/bridge-page?type=employee_' . $processId;
            $response = Http::withOptions([
                'verify' => false,
            ])->withBasicAuth($this->TX_CLIENT_ID, $this->TX_CLIENT_SECRET)->post("https://$this->TX_BASE_URL/trustedx-authserver/oauth/$this->TX_CLIENTS_LOGGED_AS/token?grant_type=authorization_code&code=$authorizationCode&redirect_uri=$redirect_uri");
            if (isset($response->json()['error'])) {
                $final = [
                    'status' => false,
                    "data" => $response->json(),
                    'message' => "Il semblerait que le code fournis soit invalide ou expiré."
                ];
            } else {
                $final =  [
                    'status' => true,
                    "data" => $response->json(),
                    'message' => "Voici votre code d'authentification."
                ];
            }
            return $final;
        } catch (HttpClientException $e) {
            Log::error($e->getMessage(), ['error' => json_encode($e)]);
            return [
                'status' => false,
                'message' => "Il semblerait que le code fournis soit invalide ou expiré."
            ];
        }
    }

    private function getAdminAccessToken($authorizationCode, $processId)
    {
        $redirect_uri = env('FRONTEND_URL') . '/bridge-page?type=citizen_' . $processId;
        try {
            $response = Http::withOptions([
                'verify' => false,
            ])->withBasicAuth($this->TX_CLIENT_ID, $this->TX_CLIENT_SECRET)->post("https://$this->TX_BASE_URL/trustedx-authserver/oauth/$this->TX_ADMINS_LOGGED_AS/token?grant_type=authorization_code&code=$authorizationCode&redirect_uri=$redirect_uri");
            if (isset($response->json()['error'])) {
                $final = [
                    'status' => false,
                    "data" => $response->json(),
                    'message' => "Il semblerait que le code fournis soit invalide ou expiré."
                ];
            } else {
                $final =  [
                    'status' => true,
                    "data" => $response->json(),
                    'message' => "Voici votre code d'authentification."
                ];
            }
            return $final;
        } catch (HttpClientException $e) {
            Log::error($e->getMessage(), ['error' => json_encode($e)]);
            return [
                'status' => false,
                'message' => "Il semblerait que le code fournis soit invalide ou expiré."
            ];
        }
    }

    private function getUserData($accessToken)
    {
        // Use the obtained access token for user info request
        $response = Http::withOptions([
            'verify' => false,
        ])->withToken($accessToken)->get("https://$this->TX_BASE_URL/trustedx-resources/openid/v1/users/me");
        return json_decode($response->getBody(), true);
    }


    // private function createIssuanceProcess($accessToken, $userData, $type)
    // {
    //     $client = new Client();
    //     $url = "https://$this->TX_BASE_URL/trustedx-resources/rap/v2/issuance_processes";
    //     $payload = [
    //         "sign_identities_group_labels" => ["gob", "server", $type],
    //         "registration_parameters" => [
    //             "notification_email" => "collabone@qualitycorporate.com",
    //             "user" => $userData
    //         ]
    //     ];

    //     try {
    //         $headers = [
    //             'Authorization' => 'Bearer ' . $accessToken
    //         ];
    //         $response = $client->post($url, [
    //             'headers' => [
    //                 'Authorization' => 'Bearer ' . $accessToken,
    //                 'Content-Type' => 'application/json'
    //             ],
    //             'json' => $payload
    //         ]);

    //         $responseData = json_decode($response->getBody(), true);

    //         $groupResponse = Http::withHeaders($headers)->get($responseData['sign_identities_group']['url']);
    //         $groupBody = json_decode($groupResponse->body(), true);

    //         $certResponse = Http::withHeaders($headers)->get($groupBody['sign_identities'][0]['url']);
    //         $certBody = json_decode($certResponse->body(), true);

    //         if (array_key_exists('certificate', $certBody['identity']['details']))
    //             $certificate['validity'] = $this->getCertData($certBody['identity']['details']['certificate']) ?? null;

    //         $certificate['details'] = $certBody['identity']['details'];

    //         if (isset($responseData['status']['value']) && $responseData['status']['value'] === 'finished') {
    //             $this->deleteIssuanceProcess($responseData['id'], $accessToken);
    //         }

    //         return ['response' => $responseData, 'cert' => $certificate];
    //     } catch (RequestException $e) {
    //         if ($e->hasResponse()) {
    //             $errorResponse = $e->getResponse();
    //             $errorBody = $errorResponse->getBody()->getContents();
    //             Log::error($e->getMessage(), ['trace' => $errorBody]);
    //             return $this->sendError($e->getMessage(), $errorBody);
    //         }
    //     }
    // }

    private function createIssuanceProcess($accessToken, $userData, $type)
    {
        $url = "https://{$this->TX_BASE_URL}/trustedx-resources/rap/v2/issuance_processes";

        $payload = [
            "sign_identities_group_labels" => ["gob", "server", $type],
            "registration_parameters" => [
                "notification_email" => "collabone@qualitycorporate.com",
                "user" => $userData
            ]
        ];

        try {
            // === 1. Création du process d’émission
            Log::info("📤 Envoi création issuance process", [
                'url' => $url,
                'payload' => $payload
            ]);

            $response = Http::withToken($accessToken)
                ->timeout(30)
                ->acceptJson()
                ->post($url, $payload);

            if ($response->failed()) {
                Log::error("❌ Erreur à la création du process", ['body' => $response->body()]);
                return $this->sendError("Erreur lors de la création du process", $response->body());
            }

            $responseData = $response->json();

            // === 2. Vérification de l’URL du groupe
            if (empty($responseData['sign_identities_group']['url'])) {
                throw new \Exception("URL du groupe d'identité manquante");
            }

            Log::info("📥 Récupération du groupe d'identité", [
                'url' => $responseData['sign_identities_group']['url']
            ]);

            $groupResponse = Http::withToken($accessToken)
                ->timeout(15)
                ->get($responseData['sign_identities_group']['url']);

            if ($groupResponse->failed()) {
                Log::error("❌ Erreur lors de la récupération du groupe", ['body' => $groupResponse->body()]);
                return $this->sendError("Erreur groupe identité", $groupResponse->body());
            }

            $groupBody = $groupResponse->json();

            // === 3. Vérification de l’identité
            if (empty($groupBody['sign_identities'][0]['url'])) {
                throw new \Exception("Pas d'URL d'identité trouvée dans le groupe");
            }

            Log::info("📥 Récupération de l’identité", [
                'url' => $groupBody['sign_identities'][0]['url']
            ]);

            $certResponse = Http::withToken($accessToken)
                ->timeout(15)
                ->get($groupBody['sign_identities'][0]['url']);

            if ($certResponse->failed()) {
                Log::error("❌ Erreur lors de la récupération du certificat", ['body' => $certResponse->body()]);
                return $this->sendError("Erreur certificat", $certResponse->body());
            }

            $certBody = $certResponse->json();

            // === 4. Extraction des infos certificat
            $certificate = [];
            if (array_key_exists('certificate', $certBody['identity']['details'])) {
                $certificate['validity'] = $this->getCertData($certBody['identity']['details']['certificate']) ?? null;
            }

            $certificate['details'] = $certBody['identity']['details'] ?? [];

            // === 5. Suppression du process si terminé
            if (($responseData['status']['value'] ?? null) === 'finished') {
                Log::info("🗑 Suppression du process terminé", ['id' => $responseData['id']]);
                $this->deleteIssuanceProcess($responseData['id'], $accessToken);
            }

            return ['response' => $responseData, 'cert' => $certificate];
        } catch (\Exception $e) {
            Log::error("⚠️ Exception dans createIssuanceProcess", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return $this->sendError("Erreur interne", $e->getMessage());
        }
    }


    private function deleteIssuanceProcess($processId, $accessToken)
    {
        $client = new Client();
        $url = "https://$this->TX_BASE_URL/trustedx-resources/rap/v2/issuance_processes/{$processId}";

        try {
            $response = $client->delete($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => 'application/json'
                ]
            ]);

            return json_decode($response->getBody(), true);
        } catch (RequestException $e) {
            if ($e->hasResponse()) {
                $errorResponse = $e->getResponse();
                $errorBody = $errorResponse->getBody()->getContents();
                Log::error($e->getMessage(), ['trace' => $errorBody]);
            }
            Log::error($e->getMessage(), ['trace' => $errorBody]);
            throw new \Exception("Erreur lors de la suppression du processus de certification: " . $e->getMessage());
        }
    }
}
