<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Admin\DecryptDocumentRequest;
use App\Services\Encryption\DocumentDecryptService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Header;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response as ScrambleResponse;
use Illuminate\Http\Response;

#[Group('Admin')]
class EncryptionController extends Controller
{
    public function __construct(
        private readonly DocumentDecryptService $decryptService,
    ) {}

    /**
     * Decrypt and display an encrypted enrollment document
     *
     * Staff reviewers and manager (`viewEncryptedDocuments` policy). `filename` is the stored encrypted file name;
     * the decrypted content is returned inline with its detected MIME type.
     * 404 when the file does not exist.
     * Exception to the JSON `{success,message,data}` envelope (§9.2): success is raw file bytes.
     */
    #[PathParameter('filename', description: 'Stored encrypted file name (basename only; no path segments).', type: 'string')]
    #[ScrambleResponse(
        200,
        description: 'Decrypted file bytes. Content-Type is the detected MIME type (often image/* or application/pdf).',
        mediaType: 'application/octet-stream',
        type: 'string',
        format: 'binary',
    )]
    #[Header('Content-Type', description: 'Detected MIME type of the decrypted file.', type: 'string')]
    #[Header('Content-Disposition', description: 'inline; filename="<basename>"', type: 'string')]
    public function decryptAndDisplay(DecryptDocumentRequest $request): Response
    {
        $this->authorize('viewEncryptedDocuments');

        $result = $this->decryptService->decryptAndDisplay(
            (string) $request->validated('filename'),
            $request->user(),
        );

        if (! $result->success) {
            abort($result->code, $result->message);
        }

        /** @var array{content: string, mime: string, filename: string} $data */
        $data = $result->data;

        return response()->make($data['content'], 200, [
            'Content-Type' => $data['mime'],
            'Content-Disposition' => 'inline; filename="'.$data['filename'].'"',
        ]);
    }
}
