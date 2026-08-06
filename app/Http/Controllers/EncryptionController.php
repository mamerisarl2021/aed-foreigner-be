<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Admin\DecryptDocumentRequest;
use App\Services\Encryption\DocumentDecryptService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

#[Group('Admin')]
class EncryptionController extends Controller
{
    public function __construct(
        private readonly DocumentDecryptService $decryptService,
    ) {}

    /**
     * Decrypt and display an encrypted enrollment document
     *
     * Staff reviewers only (viewEncryptedDocuments gate). `filename` is the stored encrypted file name;
     * the decrypted content is returned inline with its detected MIME type.
     * 404 when the file does not exist.
     */
    public function decryptAndDisplay(DecryptDocumentRequest $request): Response
    {
        Gate::authorize('viewEncryptedDocuments');

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
