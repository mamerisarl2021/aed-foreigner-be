<?php

namespace App\Http\Controllers;

use App\Models\Signature;
use App\Models\SignatureDocument;
use Carbon\Carbon;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request as Psr7Request;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class SignatureDocumentController extends BaseController
{
    public function store(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'file' => 'required|file',
        ]);

        try {
            $path = Storage::cloud()->put('pdfs', $request->file('file'));
            $url = Storage::cloud()->temporaryUrl($path, Carbon::now()->addMinutes(60));

            $signature_document = SignatureDocument::create([
                'user_id' => $request->user()->id,
                'title' => $request->title,
                'file_path' => $path,
                'status' => 'pending',
            ]);
            Signature::create([
                'signature_document_id' => $signature_document->id,
                'user_id' => $request->user()->id,
                'status' => 'pending',
            ]);

            return $this->sendResponse('Document créé avec succès.', $signature_document);
        } catch (Exception $e) {
            Log::error('Échec de la création du document:', ['exception' => $e->getMessage()]);

            return $this->sendError('Échec de la création du document.', [$e->getMessage()]);
        }
    }

    public function simpleStore(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'file' => 'required|file',
        ]);

        try {
            try {
                $path = Storage::cloud()->put('pdfs', $request->file('file'));
                if (! $path || strlen($path) === 0) {
                    throw new Exception('Le chemin retourné est vide.');
                }
            } catch (\Throwable $th) {
                Log::error('Erreur lors de l\'upload du fichier dans le cloud:', [
                    'message' => $th->getMessage(),
                    'stack' => $th->getTraceAsString(),
                ]);

                return $this->sendError('Échec de l\'upload du fichier.', [$th->getMessage()]);
            }

            $signature_document = SignatureDocument::create([
                'user_id' => $request->user()->id,
                'title' => $request->title,
                'file_path' => $path,
                'status' => 'pending',
            ]);

            return $this->sendResponse('Document créé avec succès.', $signature_document);
        } catch (Exception $e) {
            Log::error('Échec de la création du document:', ['exception' => $e->getMessage()]);

            return $this->sendError('Échec de la création du document.', [$e->getMessage()]);
        }
    }

    public function verify(Request $request)
    {
        $request->validate([
            'file' => 'required|file',
        ]);
        try {
            $base64File = base64_encode(file_get_contents($request->file('file')));
            $url = 'https://test-tx-pki.gouv.bj/trustedx-gw/SoapGateway';
            $headers = [
                'Content-Type' => 'text/xml',
                'TwsAuthN' => 'urn:safelayer:tws:policies:authentication:eseal',
                'SOAPAction' => 'Verify',
            ];
            $client = new Client;
            $body = '<?xml version="1.0" encoding="utf-8"?>
            <soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
            <soapenv:Header>
                <wsse:Security soapenv:actor="http://schemas.xmlsoap.org/soap/actor/next" soapenv:mustUnderstand="1" xmlns:wsse="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd">
                    <wsse:UsernameToken>
                        <wsse:Username>eseal_app</wsse:Username>
                        <wsse:Password Type="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-username-token-profile-1.0#PasswordText">EntPKI2000</wsse:Password>
                    </wsse:UsernameToken>
                </wsse:Security>
            </soapenv:Header>
            <soapenv:Body>
                <VerifyRequest Profile="urn:safelayer:tws:dss:1.0:profiles:pdf:1.0:verify" RequestID="9f116d3821d805702aaa" xmlns="http://www.docs.oasis-open.org/dss/2004/06/oasis-dss-1.0-core-schema-wd-27.xsd">
                    <OptionalInputs>
                        <ns1:AddCertificateValues binary="true" xsi:type="ns1:AddCertificateValuesType" xmlns:ns1="http://www.safelayer.com/TWS"/>
                    </OptionalInputs>
                    <InputDocuments>
                        <Document>
                            <Base64Data MimeType="application/pdf">'.$base64File.' </Base64Data>
                        </Document>
                    </InputDocuments>
                </VerifyRequest>
            </soapenv:Body>
            </soapenv:Envelope>';
            $request = new Psr7Request('POST', $url, $headers, $body);
            $res = $client->sendAsync($request)->wait();

            return $this->sendResponse('Vérification éffectuée avec succès.', $res->getBody()->getContents());
        } catch (Exception $e) {
            Log::error('Échec de la vérification du document:', ['exception' => $e->getMessage()]);

            return $this->sendError('Échec de la vérification du document.', [$e->getMessage()]);
        }
    }

    public function destroy(Request $request, $signature_document)
    {
        try {
            $signatureDoc = SignatureDocument::find($signature_document);

            if ($signatureDoc->canBeDeletedBy($request->user())) {
                // Suppression du fichier du stockage
                Storage::cloud()->delete($signatureDoc->file_path);

                $signatureDoc->delete();

                return $this->sendResponse('Document supprimé avec succès.');
            }

            return $this->sendError('Non autorisé.', [], 403);
        } catch (Exception $e) {
            Log::error('Échec de la suppression du document:', ['exception' => $e->getMessage()]);

            return $this->sendError('Échec de la suppression du document.', [$e->getMessage()]);
        }
    }

    public function signatureCallback(Request $request)
    {
        $status = $request->query('status');
        $documentId = $request->query('doc');
        $signerProcessId = $request->query('signer_process_id');

        if ($status === 'finished') {
            $documentUrl = config('trustedx.base_url')."/trustedx-resources/esignsp/v2/signer_processes/{$signerProcessId}/documents/{$documentId}";

            \App\Jobs\FinalizeSignatureJob::dispatch($documentUrl, $signerProcessId, $documentId);

            return $this->sendResponse('Signature en cours de finalisation.', []);
        }

        abort(500, 'Signature process not completed');
    }

    public function show($id)
    {
        try {
            $signature = SignatureDocument::with(['user', 'signatures'])->find($id);

            return $this->sendResponse('Signature récupérée avec succès.', $signature);
        } catch (Exception $e) {
            Log::error('Échec de la récupération de la signature:', ['exception' => $e->getMessage()]);

            return $this->sendError('Échec de la récupération de la signature.', [$e->getMessage()]);
        }
    }

    public function index()
    {
        try {
            // Récupérer les documents signés par l'utilisateur
            $signedDocuments = Signature::signed()
                ->with([
                    'user:id,email',
                    'document.user:id,email,profile',
                    'document' => function ($query) {
                        $query->select('id', 'title', 'file_path', 'user_id');
                    },
                ])
                ->get(['id', 'signature_document_id', 'user_id', 'status', 'created_at', 'updated_at']);

            // Récupérer les documents envoyés par l'utilisateur
            $sentDocuments = Signature::sent()
                ->with([
                    'user:id,email',
                    'document.user:id,email,profile',
                    'document' => function ($query) {
                        $query->select('id', 'title', 'file_path', 'user_id');
                    },
                ])
                ->get(['id', 'signature_document_id', 'user_id', 'status', 'created_at', 'updated_at']);

            // Récupérer les documents reçus par l'utilisateur (mais non signés)
            $receivedDocuments = Signature::received()
                ->with([
                    'user:id,email',
                    'document.user:id,email,profile',
                    'document' => function ($query) {
                        $query->select('id', 'title', 'file_path', 'user_id');
                    },
                ])
                ->get(['id', 'signature_document_id', 'user_id', 'status', 'created_at', 'updated_at']);

            $mapSignatureData = function ($signatures) {
                return $signatures->sortByDesc(function ($signature) {
                    return $signature->updated_at;
                })->map(function ($signature) {
                    return [
                        'signature_id' => $signature->id,
                        'signature_user_email' => $signature->user->email,
                        'signature_created_at' => $signature->created_at,
                        'signature_updated_at' => $signature->updated_at,
                        'document_id' => $signature->document->id,
                        'document_title' => $signature->document->title,
                        'document_creator_email' => $signature->document->user->email,
                        'document_creator_link' => $signature->document->user->link,
                        'document_link' => $signature->document->link, // Use the accessor to get the temporary URL
                    ];
                });
            };

            $mapCreatedAtSignatureData = function ($signatures) {
                return $signatures->sortByDesc(function ($signature) {
                    return $signature->created_at;
                })->map(function ($signature) {
                    return [
                        'signature_id' => $signature->id,
                        'signature_user_email' => $signature->user->email,
                        'signature_created_at' => $signature->created_at,
                        'signature_updated_at' => $signature->updated_at,
                        'document_id' => $signature->document->id,
                        'document_title' => $signature->document->title,
                        'document_creator_email' => $signature->document->user->email,
                        'document_creator_link' => $signature->document->user->link,
                        'document_link' => $signature->document->link, // Use the accessor to get the temporary URL
                    ];
                });
            };

            // Retourner la réponse JSON avec les documents organisés
            return response()->json([
                'signed' => $mapSignatureData($signedDocuments),
                'sent' => $mapCreatedAtSignatureData($sentDocuments),
                'tosign' => $mapCreatedAtSignatureData($receivedDocuments),
            ]);
        } catch (Exception $e) {
            Log::error('Fetching users failed: '.$e->getMessage());

            return $this->sendError('Échec de la récupération des documents.', [$e->getMessage()]);
        }
    }

    public function indexMobile()
    {
        try {
            // Récupérer les documents signés par l'utilisateur
            $signedDocuments = Signature::signed()
                ->with([
                    'user:id,email',
                    'document.user:id,email,profile',
                    'document' => function ($query) {
                        $query->select('id', 'title', 'file_path', 'user_id');
                    },
                ])
                ->get(['id', 'signature_document_id', 'user_id', 'status', 'created_at', 'updated_at']);

            // Récupérer les documents envoyés par l'utilisateur
            $sentDocuments = Signature::sent()
                ->with([
                    'user:id,email',
                    'document.user:id,email,profile',
                    'document' => function ($query) {
                        $query->select('id', 'title', 'file_path', 'user_id');
                    },
                ])
                ->get(['id', 'signature_document_id', 'user_id', 'status', 'created_at', 'updated_at']);

            // Récupérer les documents reçus par l'utilisateur (mais non signés)
            $receivedDocuments = Signature::received()
                ->with([
                    'user:id,email',
                    'document.user:id,email,profile',
                    'document' => function ($query) {
                        $query->select('id', 'title', 'file_path', 'user_id');
                    },
                ])
                ->get(['id', 'signature_document_id', 'user_id', 'status', 'created_at', 'updated_at']);

            // Map the results to include the necessary information
            $mapSignatureData = function ($signatures) {
                return $signatures->map(function ($signature) {
                    return [
                        'signature_id' => $signature->id,
                        'signature_user_email' => $signature->user->email,
                        'signature_created_at' => $signature->created_at,
                        'signature_updated_at' => $signature->updated_at,
                        'document_id' => $signature->document->id,
                        'document_title' => $signature->document->title,
                        'document_creator_email' => $signature->document->user->email,
                        'document_creator_link' => $signature->document->user->link,
                        'document_link' => $signature->document->link, // Use the accessor to get the temporary URL
                    ];
                });
            };

            // Retourner la réponse JSON avec les documents organisés
            return response()->json([
                'signed' => $mapSignatureData($signedDocuments),
                'sent' => $mapSignatureData($sentDocuments),
                'tosign' => $mapSignatureData($receivedDocuments),
            ]);
        } catch (Exception $e) {
            Log::error('Fetching users failed: '.$e->getMessage());

            return $this->sendError('Échec de la récupération des documents.', [$e->getMessage()]);
        }
    }

    /**
     * Handle the SOAP XML response.
     *
     * @param  Request  $request
     * @return Response
     */
    /**
     * Safely get the value of an XPath query.
     *
     * @param  \SimpleXMLElement  $xml
     * @param  string  $xpath
     * @param  bool  $optional
     * @return string|null
     */
}
