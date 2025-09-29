<?php

namespace App\Http\Controllers;

use App\Mail\DocumentSignedMail;
use App\Mail\SignatureInvitationMail;
use App\Mail\SignatureRejectedMail;
use App\Models\Signature;
use App\Models\SignatureDocument;
use App\Models\Stamp;
use App\Models\User;
use App\Traits\AuthTrait;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\MultipartStream;
use GuzzleHttp\Psr7\Request as Psr7Request;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use phpseclib3\File\ASN1;
use setasign\Fpdi\Fpdi;

class SignatureController extends BaseController
{
    use AuthTrait;

    public function store(string $userId, string $documentId)
    {
        try {
            if (SignatureDocument::where('user_id', Auth::id())->where('id', $documentId)->count() != 1) {
                return $this->sendError('Vous n\'êtes pas le propriétaire du document.', [], 403);
            }
            $existingSignature = Signature::where('signature_document_id', $documentId)
                ->where('user_id', $userId)
                ->first();

            $user = User::where('id', $userId)->first();
            if (!$existingSignature) {
                $created = Signature::create([
                    'signature_document_id' => $documentId,
                    'user_id' => $userId,
                    'status' => 'pending',
                ]);
                Mail::to($user->email)->queue(new SignatureInvitationMail($created));
            } else {
                return $this->sendError('Vous avez déjà invité ce utilisateur sur ce document', [], 400);
            }

            return $this->sendResponse('Invitation envoyée avec succès.', []);
        } catch (\Exception $e) {
            Log::error('Échec de la création du document:', ['exception' => $e->getMessage()]);
            return $this->sendError('Échec de la création du document.', [$e->getMessage()]);
        }
    }


    public function storeMultiple(string $documentId, Request $request)
    {
        $request->validate([
            'users' => 'required|array',
            'users.*.email' => 'required|exists:users,email',
            'users.*.location' => 'required|string'
        ]);
        $users = $request->input('users');

        try {
            if (SignatureDocument::where('user_id', Auth::id())->where('id', $documentId)->count() != 1) {
                return $this->sendError('Vous n\'êtes pas le propriétaire du document.', [], 403);
            }
            $signatures = [];

            // Démarrage de la transaction
            DB::beginTransaction();

            foreach ($users as $us) {
                // Récupérer l'utilisateur par email
                $user = User::where('email', $us['email'])->first();

                // Vérifier si la signature existe déjà
                $existingSignature = Signature::where('signature_document_id', $documentId)
                    ->where('user_id', $user->id)
                    ->first();

                if (!$existingSignature) {
                    // Créer une nouvelle signature
                    $created = Signature::create([
                        'signature_document_id' => $documentId,
                        'user_id' => $user->id,
                        'status' => 'pending',
                        'location' => $us['location'],
                        'toTimestamp' => $us['toTimestamp'],
                    ]);
                    array_push($signatures, $created);
                    Mail::to($user->email)->queue(new SignatureInvitationMail($created));
                } else {
                    array_push($signatures, $existingSignature);
                    Mail::to($user->email)->queue(new SignatureInvitationMail($existingSignature));
                }
            }

            // Valider la transaction si tout est correct
            DB::commit();

            return $this->sendResponse('Invitations envoyées avec succès.', $signatures);
        } catch (\Exception $e) {
            // Annuler la transaction en cas d'erreur
            DB::rollBack();

            Log::error('Échec de la création du document:', ['exception' => $e->getMessage()]);
            return $this->sendError('Échec de la création du document.', [$e->getMessage()]);
        }
    }


    public function init(SignatureDocument $signature_document, String $token)
    {
        try {
            $pdfPath = $signature_document->file_path;
            $signatureProcess = $this->createSignatureProcess($pdfPath, $token);

            if (!$signatureProcess['status']) {
                return $this->sendError($signatureProcess['message'], null, $signatureProcess['code']);
            }
            return $this->sendResponse('La signature a bien été initié sur le document vous serez redirigé pour la finalisation du processus.', $signatureProcess);
        } catch (\Exception $e) {
            Log::error('Échec de la signature du document:', ['exception' => $e->getMessage()]);
            return $this->sendError('Échec de la signature du document.', [$e->getMessage()]);
        }
    }

    public function initWithPosition(SignatureDocument $signature_document, Signature $signature, String $token, Stamp $stamp, Request $request)
    {
        try {
            $pdfPath = $signature_document->file_path;
            $path = $stamp->fichier;
            $location = $signature->location;

            // Récupérer les préférences utilisateur depuis la requête
            $showName = $request->input('show_name', false);
            $showLocation = $request->input('show_location', false);
            $showDate = $request->input('show_date', false);

            // Construire les détails de la signature en fonction des préférences
            $signatureDetails = [
                'signature_details' => [
                    'details' => [],
                ],
            ];

            if ($showName) {
                $signatureDetails['signature_details']['details'][] = [
                    'type' => 'subject',
                    'title' => 'Identité du signataire: '
                ];
            }

            if ($showLocation) {
                $signatureDetails['signature_details']['details'][] = [
                    'type' => 'location',
                    'title' => 'Lieu: '
                ];
            }

            $timezone = env('APP_TIMEZONE');

            if ($showDate) {
                $signatureDetails['signature_details']['details'][] = [
                    'type' => 'date',
                    'title' => 'Date: '
                ];
            }

            // Ajout des informations de signature
            $signatureProcess = $this->createSignatureProcessWithLocation($pdfPath, $token, $location, $path, $signatureDetails);

            if (!$signatureProcess['status']) {
                return $this->sendError($signatureProcess['message'], null, $signatureProcess['code']);
            }

            return $this->sendResponse(
                'La signature a bien été initiée sur le document. Vous serez redirigé pour la finalisation du processus.',
                $signatureProcess
            );
        } catch (\Exception $e) {
            Log::error('Échec de la signature du document:', ['exception' => $e]);
            return $this->sendError('Échec de la signature du document.', [$e->getMessage()]);
        }
    }

    public function sign(SignatureDocument $signature_document, String $processId, String $token)
    {
        try {
            $pdfPath = $signature_document->file_path;
            $signatureProcess = $this->obtainedDocumentInformation($processId, $token);

            if (!$signatureProcess['status']) {
                return $this->sendError($signatureProcess['data'], null, $signatureProcess['code']);
            }

            $process = $signatureProcess['data'];
            $signature = Signature::where('user_id', Auth::id())->where('signature_document_id', $signature_document->id)->first();
            $documentUrl = $process['documents'][0]['content'];

            $documentResponse = $signature->toTimestamp ? $this->getSignedDocumentWithTimestamp($documentUrl, $token, $pdfPath) :  $this->getSignedDocument($documentUrl, $token, $pdfPath);

            if (!$documentResponse['status']) {
                return $this->sendError($documentResponse['data'], '', $documentResponse['code']);
            }
            $this->deleteSignatureProcess($processId, $token);

            $signature->status = 'signed';
            $signature->save();

            // Check if all signatures are completed
            if ($signature_document->signatures->every(fn($sig) => $sig->status === 'signed')) {
                $signature_document->status = 'completed';
                $signature_document->save();
            }

            $documentOwner = $signature_document->user;
            Mail::to($documentOwner->email)->queue(new DocumentSignedMail($signature));

            return $this->sendResponse('Document signé avec succès.', $signature_document);
        } catch (\Exception $e) {
            Log::error('Échec de la signature du document:', ['exception' => $e]);
            return $this->sendError('Échec de la signature du document.', [$e->getMessage()]);
        }
    }

    public function timestampDocument($filePath)
    {
        // Lire le contenu du fichier
        $documentContent = File::get($filePath);

        // Générer le hachage du document
        $documentHash = hash('sha256', $documentContent);

        // Initialiser l'encodeur ASN1
        $asn1 = new ASN1();

        // Schéma pour encoder une requête TSQ
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
                                'algorithm' => ['type' => ASN1::TYPE_OBJECT_IDENTIFIER]
                            ]
                        ],
                        'hashedMessage' => ['type' => ASN1::TYPE_OCTET_STRING]
                    ]
                ],
                'nonce' => ['type' => ASN1::TYPE_INTEGER],
                'certReq' => ['type' => ASN1::TYPE_BOOLEAN]
            ]
        ];

        // Données pour la requête TSQ
        $request = [
            'version' => 1,
            'messageImprint' => [
                'hashAlgorithm' => ['algorithm' => '2.16.840.1.101.3.4.2.1'], // OID pour SHA-256
                'hashedMessage' => hex2bin($documentHash)
            ],
            'nonce' => random_int(PHP_INT_MIN, PHP_INT_MAX),
            'certReq' => true
        ];

        // Encoder les données en DER
        $tsq = $asn1->encodeDER($request, $tsqSchema);

        // Envoyer la requête au serveur TSA
        $response = Http::withHeaders([
            'Content-Type' => 'application/timestamp-query'
        ])->post('http://test-tsa-pki.gouv.bj', $tsq);

        if ($response->successful()) {
            // Sauvegarder la réponse du TSA
            $tspResponse = $response->body();
            File::put(storage_path('path/to/tsp_response.tsr'), $tspResponse);

            return $tspResponse;
        } else {
            throw new Exception("Erreur lors de la requête de timestamping.");
        }
    }

    public function decline(SignatureDocument $signature_document)
    {
        try {
            $signature = Signature::where('signature_document_id', $signature_document->id)
                ->where('user_id', Auth::id())
                ->firstOrFail();

            $signature->status = 'declined';
            $signature->save();

            $documentOwner = $signature_document->user;
            Mail::to($documentOwner->email)->queue(new SignatureRejectedMail($signature));

            return $this->sendResponse('Signature refusée.');
        } catch (\Exception $e) {
            Log::error('Échec du refus de la signature:', ['exception' => $e->getMessage()]);
            return $this->sendError('Échec du refus de la signature.', [$e->getMessage()]);
        }
    }

    public function createSignatureProcess($pdfPath, $access_token)
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
        )->post("https://{$this->TX_BASE_URL}/trustedx-resources/esignsp/v2/signer_processes", [
            'process' => json_encode([
                'process_type' => 'urn:safelayer:eidas:processes:document:sign:esigp',
                'labels' => [["server", "citizen"], ["server", "employee"]],
                'signer' => [
                    'signature_policy_id' => 'urn:safelayer:eidas:policies:sign:document:pdf',
                    'parameters' => [
                        'type' => 'pades-bes',
                        'default_digest_algorithm' => 'sha256',
                        'location' => 'Bénin, Cotonou',
                        // 'signature_field' => [
                        //     'name' => 'user_signature',
                        //     'location' => [
                        //         'page' => ['number' => 'last'],
                        //         'rectangle' => [
                        //             'x' => 100,
                        //             'y' => 110,
                        //             'height' => 150,
                        //             'width' => 400,
                        //         ],
                        //     ],
                        //     'appearance' => [
                        //         'signature_details' => [
                        //             ['type' => 'subject', 'title' => 'Identité du signataire: '],
                        //             ['type' => 'location', 'title' => 'Lieu de signature: '],
                        //             ['type' => 'date', 'title' => 'Date de signature: '],
                        //         ],
                        //     ],
                        // ],
                    ],
                ],
                'ui_locales' => ['en_US'],
                // "timestamp" => ["provider_id" => "urn:gob:signature:generation:policy"],
                // 'finish_callback_url' => "com.aed.mobile://signature/response",
                'finish_callback_url' => "https://local-aed.qcdigitalhub.com/waiting-page",
            ])
        ]);

        if ($response->successful()) {
            return [
                "status" => true,
                'data' => $response->json(),
                'code' => 201
            ];
        }
        return [
            "status" => false,
            'message' => $response->reason(),
            'code' => 401
        ];
    }

    public function createSignatureProcessWithLocation($pdfPath, $access_token, $location, $path, $signatureDetails)
    {
        if (Storage::cloud()->exists($pdfPath) && Storage::cloud()->exists($path)) {
            $pdfContent = Storage::cloud()->get($pdfPath);
            $base64Img = base64_encode(Storage::cloud()->get($path));
        } else {
            abort(404, 'Le fichier n\'a pas été retrouvé.');
        }

        // Create a temporary file to analyze PDF dimensions
        $tempFile = tempnam(sys_get_temp_dir(), 'pdf_');
        file_put_contents($tempFile, $pdfContent);

        try {
            // Use FPDI to get PDF dimensions
            $pdf = new Fpdi();
            $pageCount = $pdf->setSourceFile($tempFile);

            // Get the page specified in location
            $locationData = json_decode($location, true);
            $pageNo = min($locationData['page'], $pageCount);

            // Import page to get its dimensions
            $templateId = $pdf->importPage($pageNo);
            $size = $pdf->getTemplateSize($templateId);

            // Get page dimensions in mm
            $pageWidthInMm = $size['width'];
            $pageHeightInMm = $size['height'];

            // Convert dimensions to user units
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
            )->post("https://{$this->TX_BASE_URL}/trustedx-resources/esignsp/v2/signer_processes", [
                'process' => json_encode([
                    'process_type' => 'urn:safelayer:eidas:processes:document:sign:esigp',
                    'labels' => [["server", "citizen"], ["server", "employee"]],
                    'signer' => [
                        'signature_policy_id' => 'urn:safelayer:eidas:policies:sign:document:pdf',
                        'parameters' => [
                            'type' => 'pades-bes',
                            'default_digest_algorithm' => 'sha256',
                            'location' => 'Bénin, Cotonou',
                            'signature_field' => [
                                'name' => Auth::user()->name . '_Signature',
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
                                    "foreground_image" => ["binary" => $base64Img],
                                    ...$signatureDetails
                                ],
                            ],
                        ],
                    ],
                    'ui_locales' => ['en_US'],
                    // "timestamp" => ["provider_id" => "urn:gob:signature:generation:policy"],
                    // 'finish_callback_url' => "https://local-aed.qcdigitalhub.com/waiting-page-web",
                    'finish_callback_url' => "https://local-aed.qcdigitalhub.com/waiting-page-web",
                ])
            ]);
        } finally {
            // Clean up temporary file
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
        Log::debug($response);

        if ($response->successful()) {
            return [
                "status" => true,
                'data' => $response->json(),
                'code' => 201
            ];
        }
        return [
            "status" => false,
            'message' => $response->reason(),
            'code' => 401
        ];
    }

    // // Step 4: Obtain the Signed Document
    public function getSignedDocument($documentUrl, $accessToken, $finalPath)
    {
        $response = Http::withToken($accessToken)->get($documentUrl);

        if ($response->successful()) {
            Storage::cloud()->put($finalPath, $response->body());
            return [
                'status' => true,
                'code' => 200,
                'data' => "Document signé avec suucès."
            ];
        }

        return [
            'status' => false,
            'code' => 500,
            'data' => "Une erreur est survenue lors de la récupération des informations au niveau de la PKI. Nous vous prions de patienter un instant et réessayer."
        ];
    }

    // public function getSignedDocumentWithTimestamp($documentUrl, $pkiToken, $finalPath)
    // {
    //     // Step 1: Authenticate to get the Access Token
    //     $client = new Client();
    //     $headers = [
    //         'Content-Type' => 'application/json',
    //     ];
    //     $body = json_encode([
    //         'username' => $this->TIMESATAMP_API_USERNAME,
    //         'password' => $this->TIMESATAMP_API_PASSWORD,
    //     ]);

    //     try {
    //         // Send authentication request
    //         $authRequest = new Psr7Request('POST', "$this->TIMESATAMP_API_BASE_URL/api/token/", $headers, $body);
    //         $authResponse = $client->sendAsync($authRequest)->wait();

    //         // Check if authentication is successful
    //         if ($authResponse->getStatusCode() == 200) {
    //             $authData = json_decode($authResponse->getBody(), true);
    //             $accessToken = $authData['access'];

    //             $response = $client->get($documentUrl, [
    //                 'headers' => [
    //                     'Authorization' => 'Bearer ' . $pkiToken
    //                 ]
    //             ]);

    //             $fileContent = $response->getBody()->getContents();

    //             // Step 2: Send the timestamping request using the Access Token
    //             $timestampHeaders = [
    //                 'Authorization' => 'Bearer ' . $accessToken,
    //             ];

    //             $timestampResponse = $client->post("$this->TIMESATAMP_API_BASE_URL/api/timestamps/timestamp_pdf/", [
    //                 'headers' => $timestampHeaders,
    //                 'multipart' => [
    //                     [
    //                         'name' => 'file',
    //                         'contents' => $fileContent,
    //                         // 'filename' => 'signed_document.pdf',
    //                         'headers' => [
    //                             'Content-Type' => 'application/pdf',
    //                         ],
    //                     ],
    //                     [
    //                         'name' => 'field_name',
    //                         'contents' => Auth::user()->name . '_Timestamp',
    //                     ],
    //                 ],
    //             ]);

    //             // Check if timestamping is successful
    //             if ($timestampResponse->getStatusCode() == 200) {
    //                 // Save the Timestamped PDF to Cloud Storage
    //                 Storage::cloud()->put($finalPath, $timestampResponse->getBody());

    //                 return [
    //                     'status' => true,
    //                     'code' => 200,
    //                     'data' => "Document signé avec succès.",
    //                 ];
    //             } else {
    //                 return [
    //                     'status' => false,
    //                     'code' => 500,
    //                     'data' => $timestampResponse->getBody(),
    //                     'message' => "Erreur lors de l'ajout du tampon temporel au document.",
    //                 ];
    //             }
    //         } else {
    //             // Handle authentication failure
    //             return [
    //                 'status' => false,
    //                 'code' => 401,
    //                 'data' => "Échec de l'authentification. Vérifiez vos informations de connexion."
    //             ];
    //         }
    //     } catch (RequestException $e) {
    //         // Handle request errors
    //         return [
    //             'status' => false,
    //             'code' => 500,
    //             'data' => "Une erreur est survenue: " . $e->getMessage()
    //         ];
    //     }
    // }

    public function getSignedDocumentWithTimestamp($documentUrl, $pkiToken, $finalPath)
    {
        $client = new Client();
        $headers = [
            'Content-Type' => 'application/json',
        ];
        $body = json_encode([
            'username' => $this->TIMESATAMP_API_USERNAME,
            'password' => $this->TIMESATAMP_API_PASSWORD,
        ]);

        try {
            // Authentication request
            $authRequest = new Psr7Request('POST', "$this->TIMESATAMP_API_BASE_URL/api/token/", $headers, $body);
            $authResponse = $client->sendAsync($authRequest)->wait();

            if ($authResponse->getStatusCode() == 200) {
                $authData = json_decode($authResponse->getBody(), true);
                $accessToken = $authData['access'];

                // Fetch the document
                $response = $client->get($documentUrl, [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $pkiToken
                    ]
                ]);

                $fileContent = $response->getBody()->getContents();

                // Prepare multipart request
                $multipartRequest = new MultipartStream([
                    [
                        'name'     => 'file',
                        'contents' => $fileContent,
                        'filename' => 'document.pdf',
                        'headers'  => [
                            'Content-Type' => 'application/pdf',
                        ]
                    ],
                    [
                        'name'     => 'field_name',
                        'contents' => Auth::user()->name . '_Timestamp'
                    ]
                ]);

                // Send timestamping request
                $timestampResponse = $client->post("$this->TIMESATAMP_API_BASE_URL/api/timestamps/timestamp_pdf/", [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $accessToken,
                        'Content-Type'  => 'multipart/form-data; boundary=' . $multipartRequest->getBoundary()
                    ],
                    'body' => $multipartRequest
                ]);

                if ($timestampResponse->getStatusCode() == 201) {
                    // Save the Timestamped PDF to Cloud Storage
                    Storage::cloud()->put($finalPath, $timestampResponse->getBody());

                    return [
                        'status' => true,
                        'code' => 200,
                        'data' => "Document signé avec succès.",
                    ];
                } else {
                    return [
                        'status' => false,
                        'code' => 500,
                        'data' => $timestampResponse->getBody(),
                        'message' => "Erreur lors de l'ajout du tampon temporel au document.",
                    ];
                }
            } else {
                // Handle authentication failure
                return [
                    'status' => false,
                    'code' => 401,
                    'data' => "Échec de l'authentification. Vérifiez vos informations de connexion."
                ];
            }
        } catch (RequestException $e) {
            // Handle request errors
            return [
                'status' => false,
                'code' => 500,
                'data' => "Une erreur est survenue: " . $e->getMessage()
            ];
        }
    }

    // Step 5: Delete the Document Signature Process
    public function deleteSignatureProcess($signerProcessId, $accessToken)
    {
        $response = Http::withToken($accessToken)->delete("https://{$this->TX_BASE_URL}/trustedx-resources/esignsp/v2/signer_processes/{$signerProcessId}");

        if ($response->successful()) {
            return response()->json(['message' => 'Signature process deleted successfully']);
        }

        abort(500, 'Failed to delete signature process');
    }

    public function obtainedDocumentInformation(String $signerProcessId, $accessToken)
    {
        try {
            $response = Http::withToken($accessToken)->get("https://$this->TX_BASE_URL/trustedx-resources/esignsp/v2/signer_processes/$signerProcessId/documents");
            Log::debug($response);
            if ($response->successful()) {
                $output = $this->handleResponse($response);
            } else {
                $output = [
                    'status' => false,
                    'code' => 401,
                    'data' => "Une erreur est survenue lors de la récupération des informations au niveau de la PKI. Nous vous prions de patienter un instant et réessayer."
                ];
            }
        } catch (Exception $e) {
            Log::error('Une erreur est survenue lors de la récupération des informations:', ["error" => $e->getMessage()]);
            $output = [
                'status' => false,
                'code' => 500,
                'data' => "Une erreur est survenue lors de la récupération des informations au niveau de la PKI. Nous vous prions de patienter un instant et réessayer."
            ];
        }
        return $output;
    }
}
