<?php

declare(strict_types=1);

namespace App\Services;

use App\DataTransferObjects\EmailNotificationData;
use App\Enums\NotificationPlatform;
use App\Enums\NotificationTemplate;
use App\Jobs\Notifications\SendEmailNotificationJob;
use App\Models\Signature;
use App\Models\SignatureDocument;
use App\Models\Stamp;
use App\Models\User;
use App\Support\NotificationRecipient;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\MultipartStream;
use GuzzleHttp\Psr7\Request as Psr7Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use phpseclib3\File\ASN1;
use setasign\Fpdi\Fpdi;

class SignatureService
{
    private string $txBaseUrl;

    private string $timestampApiBaseUrl;

    private string $timestampApiUsername;

    private string $timestampApiPassword;

    public function __construct()
    {
        $this->txBaseUrl = config('trustedx.base_url');
        $this->timestampApiBaseUrl = config('trustedx.timestamp.url');
        $this->timestampApiUsername = config('trustedx.timestamp.username');
        $this->timestampApiPassword = config('trustedx.timestamp.password');
    }

    public function store(string $userId, string $documentId, string $authId): ServiceResult
    {
        try {
            if (SignatureDocument::where('user_id', $authId)->where('id', $documentId)->count() != 1) {
                return ServiceResult::fail('Vous n\'êtes pas le propriétaire du document.', null, 403);
            }
            $existingSignature = Signature::where('signature_document_id', $documentId)
                ->where('user_id', $userId)
                ->first();

            $user = User::where('id', $userId)->first();
            if (! $existingSignature) {
                $created = Signature::create([
                    'signature_document_id' => $documentId,
                    'user_id' => $userId,
                    'status' => 'pending',
                ]);
                $this->dispatchSignatureInvitationNotification($created, $user->email);
            } else {
                return ServiceResult::fail('Vous avez déjà invité ce utilisateur sur ce document', null, 400);
            }

            return ServiceResult::ok('Invitation envoyée avec succès.', []);
        } catch (Exception $e) {
            Log::error('Échec de la création du document:', ['exception' => $e->getMessage()]);

            return ServiceResult::fail('Échec de la création du document.', [$e->getMessage()], 500);
        }
    }

    public function storeMultiple(string $documentId, array $users, string $authId): ServiceResult
    {
        try {
            if (SignatureDocument::where('user_id', $authId)->where('id', $documentId)->count() != 1) {
                return ServiceResult::fail('Vous n\'êtes pas le propriétaire du document.', null, 403);
            }
            $signatures = [];

            DB::beginTransaction();

            foreach ($users as $us) {
                $user = User::where('email', $us['email'])->first();

                $existingSignature = Signature::where('signature_document_id', $documentId)
                    ->where('user_id', $user->id)
                    ->first();

                if (! $existingSignature) {
                    $created = Signature::create([
                        'signature_document_id' => $documentId,
                        'user_id' => $user->id,
                        'status' => 'pending',
                        'location' => $us['location'],
                        'toTimestamp' => $us['toTimestamp'] ?? false,
                    ]);
                    $signatures[] = $created;
                    $this->dispatchSignatureInvitationNotification($created, $user->email);
                } else {
                    $signatures[] = $existingSignature;
                    $this->dispatchSignatureInvitationNotification($existingSignature, $user->email);
                }
            }

            DB::commit();

            return ServiceResult::ok('Invitations envoyées avec succès.', $signatures);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Échec de la création du document:', ['exception' => $e->getMessage()]);

            return ServiceResult::fail('Échec de la création du document.', [$e->getMessage()], 500);
        }
    }

    public function init(SignatureDocument $signature_document, string $token): ServiceResult
    {
        try {
            $pdfPath = $signature_document->file_path;
            $signatureProcess = $this->createSignatureProcess($pdfPath, $token);

            if (! $signatureProcess['status']) {
                return ServiceResult::fail($signatureProcess['message'], null, $signatureProcess['code']);
            }

            return ServiceResult::ok('La signature a bien été initié sur le document vous serez redirigé pour la finalisation du processus.', $signatureProcess);
        } catch (Exception $e) {
            Log::error('Échec de la signature du document:', ['exception' => $e->getMessage()]);

            return ServiceResult::fail('Échec de la signature du document.', [$e->getMessage()], 500);
        }
    }

    public function initWithPosition(SignatureDocument $signature_document, Signature $signature, string $token, Stamp $stamp, array $preferences, User $user): ServiceResult
    {
        try {
            $pdfPath = $signature_document->file_path;
            $path = $stamp->fichier;
            $location = $signature->location;

            $signatureDetails = [
                'signature_details' => [
                    'details' => [],
                ],
            ];

            if ($preferences['show_name'] ?? false) {
                $signatureDetails['signature_details']['details'][] = [
                    'type' => 'subject',
                    'title' => 'Identité du signataire: ',
                ];
            }

            if ($preferences['show_location'] ?? false) {
                $signatureDetails['signature_details']['details'][] = [
                    'type' => 'location',
                    'title' => 'Lieu: ',
                ];
            }

            if ($preferences['show_date'] ?? false) {
                $signatureDetails['signature_details']['details'][] = [
                    'type' => 'date',
                    'title' => 'Date: ',
                ];
            }

            $signatureProcess = $this->createSignatureProcessWithLocation($pdfPath, $token, $location, $path, $signatureDetails, $user->name);

            if (! $signatureProcess['status']) {
                return ServiceResult::fail($signatureProcess['message'], null, $signatureProcess['code']);
            }

            return ServiceResult::ok(
                'La signature a bien été initiée sur le document. Vous serez redirigé pour la finalisation du processus.',
                $signatureProcess
            );
        } catch (Exception $e) {
            Log::error('Échec de la signature du document:', ['exception' => $e]);

            return ServiceResult::fail('Échec de la signature du document.', [$e->getMessage()], 500);
        }
    }

    public function sign(SignatureDocument $signature_document, string $processId, string $token, string $authId): ServiceResult
    {
        try {
            $pdfPath = $signature_document->file_path;
            $signatureProcess = $this->obtainedDocumentInformation($processId, $token);

            if (! $signatureProcess['status']) {
                return ServiceResult::fail($signatureProcess['data'], null, $signatureProcess['code']);
            }

            $process = $signatureProcess['data'];
            $signature = Signature::where('user_id', $authId)->where('signature_document_id', $signature_document->id)->first();
            $documentUrl = $process['documents'][0]['content'];

            $documentResponse = $signature->toTimestamp ? $this->getSignedDocumentWithTimestamp($documentUrl, $token, $pdfPath, $signature->user->name) : $this->getSignedDocument($documentUrl, $token, $pdfPath);

            if (! $documentResponse['status']) {
                return ServiceResult::fail($documentResponse['data'], '', $documentResponse['code']);
            }
            $this->deleteSignatureProcess($processId, $token);

            $signature->status = 'signed';
            $signature->save();

            if ($signature_document->signatures->every(fn ($sig) => $sig->status === 'signed')) {
                $signature_document->status = 'completed';
                $signature_document->save();
            }

            $documentOwner = $signature_document->user;
            $signature->loadMissing('document', 'user');
            $documentTitle = $signature->document->title;
            $variables = [
                'documentTitle' => $documentTitle,
                'signerName' => $signature->user->name,
            ];

            SendEmailNotificationJob::dispatch(new EmailNotificationData(
                subject: 'Document signé: '.$documentTitle,
                template: NotificationTemplate::DocumentSigned,
                recipients: [
                    NotificationRecipient::email($documentOwner->email, $variables),
                ],
                variables: $variables,
                type: 'DOCUMENT_SIGNED',
                platform: NotificationPlatform::from(config('notifications.platform')),
            ));

            return ServiceResult::ok('Document signé avec succès.', $signature_document);
        } catch (Exception $e) {
            Log::error('Échec de la signature du document:', ['exception' => $e]);

            return ServiceResult::fail('Échec de la signature du document.', [$e->getMessage()], 500);
        }
    }

    public function timestampDocument(string $filePath): string
    {
        $documentContent = File::get($filePath);
        $documentHash = hash('sha256', $documentContent);
        /** @phpstan-ignore-next-line */
        $asn1 = new ASN1;

        $tsqSchema = [
            'type' => ASN1::TYPE_SEQUENCE,
            'children' => [
                'version' => ['type' => ASN1::TYPE_INTEGER],
                'messageImprint' => [
                    'type' => ASN1::TYPE_SEQUENCE,
                    'children' => [
                        'hashAlgorithm' => [
                            'type' => ASN1::TYPE_SEQUENCE,
                            'children' => [
                                'algorithm' => ['type' => ASN1::TYPE_OBJECT_IDENTIFIER],
                            ],
                        ],
                        'hashedMessage' => ['type' => ASN1::TYPE_OCTET_STRING],
                    ],
                ],
                'nonce' => ['type' => ASN1::TYPE_INTEGER],
                'certReq' => ['type' => ASN1::TYPE_BOOLEAN],
            ],
        ];

        $request = [
            'version' => 1,
            'messageImprint' => [
                'hashAlgorithm' => ['algorithm' => '2.16.840.1.101.3.4.2.1'],
                'hashedMessage' => hex2bin($documentHash),
            ],
            'nonce' => random_int(PHP_INT_MIN, PHP_INT_MAX),
            'certReq' => true,
        ];

        $tsq = $asn1->encodeDER($request, $tsqSchema);

        $response = Http::withHeaders([
            'Content-Type' => 'application/timestamp-query',
        ])->post('http://test-tsa-pki.gouv.bj', $tsq);

        if ($response->successful()) {
            $tspResponse = $response->body();
            // Optional: The path was hardcoded in original
            File::put(storage_path('path/to/tsp_response.tsr'), $tspResponse);

            return $tspResponse;
        } else {
            throw new Exception('Erreur lors de la requête de timestamping.');
        }
    }

    public function decline(SignatureDocument $signature_document, string $authId): ServiceResult
    {
        try {
            $signature = Signature::where('signature_document_id', $signature_document->id)
                ->where('user_id', $authId)
                ->firstOrFail();

            $signature->status = 'declined';
            $signature->save();

            $documentOwner = $signature_document->user;
            $signature->loadMissing('document', 'user');
            $documentTitle = $signature->document->title;
            $variables = [
                'documentTitle' => $documentTitle,
                'rejecterName' => $signature->user->name,
            ];

            SendEmailNotificationJob::dispatch(new EmailNotificationData(
                subject: 'Document refusé: '.$documentTitle,
                template: NotificationTemplate::SignatureRejected,
                recipients: [
                    NotificationRecipient::email($documentOwner->email, $variables),
                ],
                variables: $variables,
                type: 'SIGNATURE_REJECTED',
                platform: NotificationPlatform::from(config('notifications.platform')),
            ));

            return ServiceResult::ok('Signature refusée.');
        } catch (Exception $e) {
            Log::error('Échec du refus de la signature:', ['exception' => $e->getMessage()]);

            return ServiceResult::fail('Échec du refus de la signature.', [$e->getMessage()], 500);
        }
    }

    public function createSignatureProcess(string $pdfPath, string $access_token): array
    {
        if (Storage::cloud()->exists($pdfPath)) {
            $pdfContent = Storage::cloud()->get($pdfPath);
        } else {
            abort(404, 'Le fichier n\'a pas été retrouvé.');
        }

        $response = Http::withToken($access_token)->attach(
            'document',
            $pdfContent,
            'document.pdf'
        )->post("https://{$this->txBaseUrl}/trustedx-resources/esignsp/v2/signer_processes", [
            'process' => json_encode([
                'process_type' => 'urn:safelayer:eidas:processes:document:sign:esigp',
                'labels' => [['server', 'citizen'], ['server', 'employee']],
                'signer' => [
                    'signature_policy_id' => 'urn:safelayer:eidas:policies:sign:document:pdf',
                    'parameters' => [
                        'type' => 'pades-bes',
                        'default_digest_algorithm' => 'sha256',
                        'location' => 'Bénin, Cotonou',
                    ],
                ],
                'ui_locales' => ['en_US'],
                'finish_callback_url' => 'https://local-aed.qcdigitalhub.com/waiting-page',
            ]),
        ]);

        if ($response->successful()) {
            return [
                'status' => true,
                'data' => $response->json(),
                'code' => 201,
            ];
        }

        return [
            'status' => false,
            'message' => $response->reason(),
            'code' => 401,
        ];
    }

    public function createSignatureProcessWithLocation(string $pdfPath, string $access_token, string $location, string $path, array $signatureDetails, string $userName): array
    {
        if (Storage::cloud()->exists($pdfPath) && Storage::cloud()->exists($path)) {
            $pdfContent = Storage::cloud()->get($pdfPath);
            $base64Img = base64_encode(Storage::cloud()->get($path));
        } else {
            abort(404, 'Le fichier n\'a pas été retrouvé.');
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'pdf_');
        file_put_contents($tempFile, $pdfContent);

        try {
            $pdf = new Fpdi;
            $pageCount = $pdf->setSourceFile($tempFile);

            $locationData = json_decode($location, true);
            $pageNo = min($locationData['page'], $pageCount);

            $templateId = $pdf->importPage($pageNo);
            $size = $pdf->getTemplateSize($templateId);

            $pageWidthInMm = $size['width'];
            $pageHeightInMm = $size['height'];

            $pageWidth = $pageWidthInMm * 2.83465;
            $pageHeight = $pageHeightInMm * 2.83465;

            $xPosition = ($locationData['x'] / 100) * $pageWidth;
            $yPosition = ($locationData['y'] / 100) * $pageHeight;

            $width = $locationData['width'];
            $height = $locationData['height'];

            $response = Http::withToken($access_token)->attach(
                'document',
                $pdfContent,
                'document.pdf'
            )->post("https://{$this->txBaseUrl}/trustedx-resources/esignsp/v2/signer_processes", [
                'process' => json_encode([
                    'process_type' => 'urn:safelayer:eidas:processes:document:sign:esigp',
                    'labels' => [['server', 'citizen'], ['server', 'employee']],
                    'signer' => [
                        'signature_policy_id' => 'urn:safelayer:eidas:policies:sign:document:pdf',
                        'parameters' => [
                            'type' => 'pades-bes',
                            'default_digest_algorithm' => 'sha256',
                            'location' => 'Bénin, Cotonou',
                            'signature_field' => [
                                'name' => $userName.'_Signature',
                                'location' => [
                                    'page' => ['number' => $pageNo],
                                    'rectangle' => [
                                        'x' => round($xPosition, 2),
                                        'y' => 1 * round($yPosition, 2),
                                        'height' => round($height, 2),
                                        'width' => round($width, 2),
                                    ],
                                ],
                                'appearance' => [
                                    'foreground_image' => ['binary' => $base64Img],
                                    ...$signatureDetails,
                                ],
                            ],
                        ],
                    ],
                    'ui_locales' => ['en_US'],
                    'finish_callback_url' => 'https://local-aed.qcdigitalhub.com/waiting-page-web',
                ]),
            ]);
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
        Log::debug($response->body());

        if ($response->successful()) {
            return [
                'status' => true,
                'data' => $response->json(),
                'code' => 201,
            ];
        }

        return [
            'status' => false,
            'message' => $response->reason(),
            'code' => 401,
        ];
    }

    public function getSignedDocument(string $documentUrl, string $accessToken, string $finalPath): array
    {
        $response = Http::withToken($accessToken)->get($documentUrl);

        if ($response->successful()) {
            Storage::cloud()->put($finalPath, $response->body());

            return [
                'status' => true,
                'code' => 200,
                'data' => 'Document signé avec suucès.',
            ];
        }

        return [
            'status' => false,
            'code' => 500,
            'data' => 'Une erreur est survenue lors de la récupération des informations au niveau de la PKI. Nous vous prions de patienter un instant et réessayer.',
        ];
    }

    public function getSignedDocumentWithTimestamp(string $documentUrl, string $pkiToken, string $finalPath, string $userName): array
    {
        $client = new Client;
        $headers = [
            'Content-Type' => 'application/json',
        ];
        $body = json_encode([
            'username' => $this->timestampApiUsername,
            'password' => $this->timestampApiPassword,
        ]);

        try {
            $authRequest = new Psr7Request('POST', "{$this->timestampApiBaseUrl}/api/token/", $headers, $body);
            $authResponse = $client->sendAsync($authRequest)->wait();

            if ($authResponse->getStatusCode() == 200) {
                $authData = json_decode($authResponse->getBody()->getContents(), true);
                $accessToken = $authData['access'];

                $response = $client->get($documentUrl, [
                    'headers' => [
                        'Authorization' => 'Bearer '.$pkiToken,
                    ],
                ]);

                $fileContent = $response->getBody()->getContents();

                $multipartRequest = new MultipartStream([
                    [
                        'name' => 'file',
                        'contents' => $fileContent,
                        'filename' => 'document.pdf',
                        'headers' => [
                            'Content-Type' => 'application/pdf',
                        ],
                    ],
                    [
                        'name' => 'field_name',
                        'contents' => $userName.'_Timestamp',
                    ],
                ]);

                $timestampResponse = $client->post("{$this->timestampApiBaseUrl}/api/timestamps/timestamp_pdf/", [
                    'headers' => [
                        'Authorization' => 'Bearer '.$accessToken,
                        'Content-Type' => 'multipart/form-data; boundary='.$multipartRequest->getBoundary(),
                    ],
                    'body' => $multipartRequest,
                ]);

                if ($timestampResponse->getStatusCode() == 201) {
                    Storage::cloud()->put($finalPath, $timestampResponse->getBody()->getContents());

                    return [
                        'status' => true,
                        'code' => 200,
                        'data' => 'Document signé avec succès.',
                    ];
                } else {
                    return [
                        'status' => false,
                        'code' => 500,
                        'data' => $timestampResponse->getBody()->getContents(),
                        'message' => "Erreur lors de l'ajout du tampon temporel au document.",
                    ];
                }
            } else {
                return [
                    'status' => false,
                    'code' => 401,
                    'data' => "Échec de l'authentification. Vérifiez vos informations de connexion.",
                ];
            }
        } catch (RequestException $e) {
            return [
                'status' => false,
                'code' => 500,
                'data' => 'Une erreur est survenue: '.$e->getMessage(),
            ];
        }
    }

    public function deleteSignatureProcess(string $signerProcessId, string $accessToken): bool
    {
        $response = Http::withToken($accessToken)->delete("https://{$this->txBaseUrl}/trustedx-resources/esignsp/v2/signer_processes/{$signerProcessId}");

        if ($response->successful()) {
            return true;
        }

        throw new Exception('Failed to delete signature process');
    }

    public function obtainedDocumentInformation(string $signerProcessId, string $accessToken): array
    {
        try {
            $response = Http::withToken($accessToken)->get("https://{$this->txBaseUrl}/trustedx-resources/esignsp/v2/signer_processes/$signerProcessId/documents");
            Log::debug($response->body());
            if ($response->successful()) {
                $output = $this->handleResponse($response);
            } else {
                $output = [
                    'status' => false,
                    'code' => 401,
                    'data' => 'Une erreur est survenue lors de la récupération des informations au niveau de la PKI. Nous vous prions de patienter un instant et réessayer.',
                ];
            }
        } catch (Exception $e) {
            Log::error('Une erreur est survenue lors de la récupération des informations:', ['error' => $e->getMessage()]);
            $output = [
                'status' => false,
                'code' => 500,
                'data' => 'Une erreur est survenue lors de la récupération des informations au niveau de la PKI. Nous vous prions de patienter un instant et réessayer.',
            ];
        }

        return $output;
    }

    private function dispatchSignatureInvitationNotification(Signature $signature, string $recipientEmail): void
    {
        $signature->loadMissing('document.user');

        $documentTitle = $signature->document->title;
        $variables = [
            'documentTitle' => $documentTitle,
            'inviterName' => $signature->document->user->name,
            'signatureLink' => config('app.frontend_url').'/backoffice/client/received-documents',
        ];

        SendEmailNotificationJob::dispatch(new EmailNotificationData(
            subject: 'Invitation de signature pour '.$documentTitle,
            template: NotificationTemplate::SignatureInvitation,
            recipients: [
                NotificationRecipient::email($recipientEmail, $variables),
            ],
            variables: $variables,
            type: 'SIGNATURE_INVITATION',
            platform: NotificationPlatform::from(config('notifications.platform')),
        ));
    }

    private function handleResponse($response): array
    {
        $statusCode = $response->getStatusCode();

        if ($statusCode === 200) {
            return [
                'status' => true,
                'code' => $statusCode,
                'data' => json_decode($response->getBody()->getContents(), true),
            ];
        } elseif ($statusCode === 401) {
            return [
                'status' => false,
                'code' => $statusCode,
                'data' => 'Unauthorized',
            ];
        } elseif ($statusCode === 404) {
            return [
                'status' => false,
                'code' => $statusCode,
                'data' => 'Aucun process avec l\'id fourni n\'a été trouvé.',
            ];
        } else {
            Log::error('Une erreur est survenue lors de la récupération des informations:', ['error' => $response->getBody()->getContents()]);

            return [
                'status' => false,
                'code' => $statusCode,
                'data' => 'Nous avons des difficultés à communiquer avec la PKI veuillez réessayer un peu plus tard.',
            ];
        }
    }
}
