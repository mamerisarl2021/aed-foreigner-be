<?php

namespace App\Traits;

use App\Models\Attachment;
use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

trait AttachmentTrait
{
    /**
     * @var Model
     */
    protected $model;

    protected $client;

    private $CLIENT_ID;

    private $TX_BASE_URL;

    private $ANIP_BASE_URL;

    private $CLIENT_SECRET;

    use EncryptionTrait;

    public function __construct(User $model)
    {
        $this->model = $model;
        $this->CLIENT_SECRET = env('CLIENT_ID');
        $this->TX_BASE_URL = env('TX_BASE_URL');
        $this->ANIP_BASE_URL = env('ANIP_BASE_URL');
        $this->CLIENT_ID = env('CLIENT_SECRET');
    }

    public function attachFiles(mixed $files, string $attachmentId)
    {
        try {
            foreach ($files as $file) {
                // Créer l'identité ADVANCED
                $filePath = Storage::cloud()->put('docs', $file);

                $extension = $file->getClientOriginalExtension();
                $doc = [
                    'name' => $file->getClientOriginalName(),
                    'type' => $extension,
                    'path' => $filePath,
                    'attachment_id' => $attachmentId,
                    'status' => 'PENDING',
                ];
                Document::create($doc);
            }
            $document = Attachment::with(['documents'])->find($attachmentId);

            return [
                'status' => true,
                'data' => $document,
                'message' => 'Pièce jointe crée avec succès.',
            ];
        } catch (\Exception $e) {
            Log::error('Creating document failed: '.$e->getMessage());

            return [
                'status' => false,
                'message' => 'Erreur pendant la création de la pièce jointe.',
            ];
        }
    }
}
