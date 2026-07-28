<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Traits\EncryptionTrait;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

#[Group('Admin')]
class EncryptionController extends Controller
{
    use EncryptionTrait;

    /**
     * Decrypt and display an encrypted enrollment document
     *
     * Staff only (viewAudits gate). `filename` is the stored encrypted file name;
     * the decrypted content is returned inline with its detected MIME type.
     * 404 when the file does not exist.
     */
    public function decryptAndDisplay(string $filename): Response
    {
        Gate::authorize('viewAudits');

        $filename = basename($filename);
        if ($filename === '' || str_contains($filename, '..')) {
            abort(404);
        }

        if (! Storage::exists("public/docs/{$filename}")) {
            abort(404);
        }

        $base64EncodedContent = $this->getEncFile($filename, 'docs');
        $fileContent = base64_decode($base64EncodedContent);

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $fileType = $finfo->buffer($fileContent);

        return response()->make($fileContent, 200, [
            'Content-Type' => $fileType,
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
    }
}
