<?php

namespace App\Services;

use App\Models\Attachment;
use App\Models\Document;
use Exception;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class AttachmentUploadService
{
    /**
     * Upload files and associate them with an Attachment record.
     *
     * @param  array<int, UploadedFile>  $files
     * @return array{status: bool, message: string, data?: Attachment}
     */
    public function attachFiles(array $files, int $attachmentId): array
    {
        try {
            foreach ($files as $file) {
                $filePath = Storage::cloud()->put('docs', $file);
                $extension = $file->getClientOriginalExtension();

                Document::create([
                    'name' => $file->getClientOriginalName(),
                    'type' => $extension,
                    'path' => $filePath,
                    'attachment_id' => $attachmentId,
                    'status' => 'PENDING',
                ]);
            }

            $document = Attachment::with(['documents'])->find($attachmentId);

            return [
                'status' => true,
                'data' => $document,
                'message' => 'Pièce jointe crée avec succès.',
            ];
        } catch (Exception $e) {
            Log::error('Creating document failed: '.$e->getMessage());

            return [
                'status' => false,
                'message' => 'Erreur pendant la création de la pièce jointe.',
            ];
        }
    }
}
