<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\SignatureService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class FinalizeSignatureJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly string $documentUrl,
        public readonly string $signerProcessId,
        public readonly string $documentId
    ) {}

    public function handle(SignatureService $signatureService, \App\Services\PKI\TrustedXClientService $trustedXClientService): void
    {
        try {
            // Get an admin token to interact with TrustedX
            $adminTokenResult = $trustedXClientService->getToken('urn:safelayer:eidas:account:user:manage');
            if (! $adminTokenResult['status'] || empty($adminTokenResult['token'])) {
                Log::error('FinalizeSignatureJob: Failed to get admin token', ['result' => $adminTokenResult['message']]);
                return;
            }

            $accessToken = $adminTokenResult['token'];

            // We need a path to save the final document. We will store it in a generic path
            // or we could look up the SignatureDocument if we mapped process_id to it.
            // For now, we will store it in 'pdfs/signed_' . $this->documentId . '.pdf'
            $finalPath = 'pdfs/signed_' . $this->documentId . '.pdf';

            $result = $signatureService->getSignedDocument($this->documentUrl, $accessToken, $finalPath);

            if ($result['status'] ?? false) {
                Log::info("FinalizeSignatureJob: Successfully downloaded signed document {$this->documentId}");
            } else {
                Log::error("FinalizeSignatureJob: Failed to download document", ['result' => $result]);
            }
        } catch (\Exception $e) {
            Log::error("FinalizeSignatureJob Exception: " . $e->getMessage());
            throw $e;
        }
    }
}
