<?php

declare(strict_types=1);

namespace App\Services\Encryption;

use App\Enums\ActivityLogAction;
use App\Models\User;
use App\Services\ActivityLog\ActivityLogService;
use App\Services\ServiceResult;
use App\Traits\EncryptionTrait;
use Illuminate\Support\Facades\Storage;

final class DocumentDecryptService
{
    use EncryptionTrait;

    public function __construct(
        private readonly ActivityLogService $activityLog,
    ) {}

    /**
     * Decrypt an encrypted enrollment document for display.
     */
    public function decryptAndDisplay(string $filename, ?User $actor): ServiceResult
    {
        $filename = basename($filename);
        if ($filename === '' || str_contains($filename, '..')) {
            return ServiceResult::fail('Document introuvable.', null, 404);
        }

        if (! Storage::exists("public/docs/{$filename}")) {
            return ServiceResult::fail('Document introuvable.', null, 404);
        }

        $this->activityLog->record(
            ActivityLogAction::DocumentDechiffre,
            sprintf(
                '%s a consulté le document chiffré %s.',
                ActivityLogService::actorLabel($actor),
                $filename
            ),
            is_string($actor?->id) ? $actor->id : null,
            null,
            ['filename' => $filename],
        );

        $base64EncodedContent = $this->getEncFile($filename, 'docs');
        $fileContent = base64_decode($base64EncodedContent);

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->buffer($fileContent) ?: 'application/octet-stream';

        return ServiceResult::ok('Document déchiffré.', [
            'content' => $fileContent,
            'mime' => $mime,
            'filename' => $filename,
        ]);
    }
}
