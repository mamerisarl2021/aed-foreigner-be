<?php

namespace App\Jobs;

use App\Models\Attachment;
use App\Models\Document;
use App\Traits\AttachmentTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Exception;

class ProcessStructureFilesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, AttachmentTrait;

    private int $structureId;
    private array $attachmentsData;
    private array $files;

    public $tries = 3;
    public $timeout = 300; // 5 minutes

    public function __construct(int $structureId, array $attachmentsData, array $files)
    {
        $this->structureId = $structureId;
        $this->attachmentsData = $attachmentsData;
        $this->files = $files;
    }

    public function handle(): void
    {
        Log::info('Processing structure files', [
            'structure_id' => $this->structureId,
            'attachments_count' => count($this->attachmentsData)
        ]);

        DB::beginTransaction();
        try {
            // Récupérer les attachments créés pour cette structure
            $attachments = Attachment::where('structure_id', $this->structureId)
                ->where('status', 'PROCESSING')
                ->orderBy('id')
                ->get();

            foreach ($this->attachmentsData as $idx => $attachmentData) {
                if (!isset($attachments[$idx])) {
                    Log::warning('Attachment not found for index', ['index' => $idx]);
                    continue;
                }

                $attachment = $attachments[$idx];
                
                // Traiter les fichiers pour cet attachment
                $filesKey = "structure.attachements.{$idx}.files";
                if (isset($this->files[$filesKey])) {
                    $uploadedFiles = $this->processAttachmentFiles($this->files[$filesKey], $attachment->id);
                    
                    if ($uploadedFiles['status']) {
                        $attachment->update([
                            'status' => $attachmentData['status'] ?? 'SENT',
                            'message' => $attachmentData['message'] ?? null,
                        ]);
                        
                        Log::info('Files processed successfully', [
                            'attachment_id' => $attachment->id,
                            'files_count' => count($uploadedFiles['data'])
                        ]);
                    } else {
                        $attachment->update([
                            'status' => 'REJECTED',
                            'message' => 'Erreur lors du traitement des fichiers: ' . $uploadedFiles['message'],
                        ]);
                        
                        Log::error('Failed to process files', [
                            'attachment_id' => $attachment->id,
                            'error' => $uploadedFiles['message']
                        ]);
                    }
                } else {
                    // Pas de fichiers, juste mettre à jour le statut
                    $attachment->update([
                        'status' => $attachmentData['status'] ?? 'SENT',
                        'message' => $attachmentData['message'] ?? null,
                    ]);
                }
            }

            DB::commit();
            Log::info('Structure files processing completed', ['structure_id' => $this->structureId]);

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Structure files processing failed', [
                'structure_id' => $this->structureId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Marquer tous les attachments en erreur
            Attachment::where('structure_id', $this->structureId)
                ->where('status', 'PROCESSING')
                ->update([
                    'status' => 'REJECTED',
                    'message' => 'Erreur technique lors du traitement des fichiers.'
                ]);
            
            throw $e;
        }
    }

    private function processAttachmentFiles(array $files, int $attachmentId): array
    {
        try {
            $uploadedFiles = [];
            
            foreach ($files as $file) {
                // Upload du fichier
                $path = Storage::cloud()->put('attachments', $file);
                
                // Créer l'enregistrement Document
                $document = Document::create([
                    'name' => $file->getClientOriginalName(),
                    'path' => $path,
                    'size' => $file->getSize(),
                    'mime_type' => $file->getMimeType(),
                    'attachment_id' => $attachmentId,
                ]);
                
                $uploadedFiles[] = [
                    'document_id' => $document->id,
                    'path' => $path,
                    'original_name' => $file->getClientOriginalName(),
                ];
            }
            
            return [
                'status' => true,
                'message' => 'Fichiers traités avec succès',
                'data' => $uploadedFiles,
            ];
            
        } catch (Exception $e) {
            // Nettoyer les fichiers déjà uploadés en cas d'erreur
            foreach ($uploadedFiles ?? [] as $uploadedFile) {
                if (Storage::cloud()->exists($uploadedFile['path'])) {
                    Storage::cloud()->delete($uploadedFile['path']);
                }
            }
            
            return [
                'status' => false,
                'message' => $e->getMessage(),
                'data' => [],
            ];
        }
    }

    public function failed(Exception $exception): void
    {
        Log::error('ProcessStructureFilesJob failed permanently', [
            'structure_id' => $this->structureId,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);
        
        // Marquer tous les attachments en erreur définitive
        Attachment::where('structure_id', $this->structureId)
            ->where('status', 'PROCESSING')
            ->update([
                'status' => 'REJECTED',
                'message' => 'Échec définitif du traitement des fichiers après plusieurs tentatives.'
            ]);
    }
}
