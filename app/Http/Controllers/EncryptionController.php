<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ActivityLogAction;
use App\Services\ActivityLog\ActivityLogService;
use App\Traits\EncryptionTrait;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

#[Group('Admin')]
class EncryptionController extends Controller
{
    use EncryptionTrait;

    public function __construct(
        private readonly ActivityLogService $activityLog,
    ) {}

    /**
     * Decrypt and display an encrypted enrollment document
     *
     * Staff reviewers only (viewEncryptedDocuments gate). `filename` is the stored encrypted file name;
     * the decrypted content is returned inline with its detected MIME type.
     * 404 when the file does not exist.
     */
    public function decryptAndDisplay(Request $request, string $filename): Response
    {
        Gate::authorize('viewEncryptedDocuments');

        $filename = basename($filename);
        if ($filename === '' || str_contains($filename, '..')) {
            abort(404);
        }

        if (! Storage::exists("public/docs/{$filename}")) {
            abort(404);
        }

        $user = $request->user();
        $this->activityLog->record(
            ActivityLogAction::DocumentDechiffre,
            sprintf(
                '%s a consulté le document chiffré %s.',
                ActivityLogService::actorLabel($user),
                $filename
            ),
            is_string($user?->id) ? $user->id : null,
            null,
            ['filename' => $filename],
        );

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
