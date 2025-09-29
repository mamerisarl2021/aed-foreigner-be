<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Attachment;
use App\Models\Document;
use App\Traits\AttachmentTrait;
use App\Traits\AuditTrait;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class AttachmentController extends BaseController
{
    use AttachmentTrait;
    use AuditTrait;
    
    public function show($id)
    {
        try {
            $attachment = Attachment::findOrFail($id);
            return $this->sendResponse(
                'Pièce jointe',
                [
                    'piece' => $attachment
                ]
            );
        } catch (\Exception $e) {
            Log::error('Fetching attachment failed: ' . $e->getMessage());
            return response()->json(['error' => 'Problème lors de la récupération de la pièce jointe.'], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'name' => 'required|string|max:255',
                'structure_id' => 'required|exists:structures,id',
                'status' => 'required',
                'message' => 'required|string',
                'files.*' => 'required|mimes:pdf,docx,doc,xls,mp4,png,jpeg,jpg|max:2048'
            ]);

            $attachment = Attachment::create($validatedData);
            $response = $this->attachFiles($request->file("files"), $attachment["id"]);

            return $response['status'] ?  $this->sendResponse($response["message"], $response["data"]) : $this->sendError($response["message"], null, 400);
        } catch (\Exception $e) {
            Log::error('Creating attachment failed: ' . $e->getMessage());
            return $this->sendError('Une ereur est survenue pendant la création de la pièce jointe.', null, 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $validatedData = $request->validate([
                'name' => 'required|string|max:255',
                'files.*' => 'required|mimes:pdf,docx,doc,xls,mp4,png,jpeg,jpg'
            ]);

            $attachment = Attachment::findOrFail($id);
            $attachment->update($validatedData);
            $response = $this->attachFiles($request->file("files"), $id);

            return $response['status'] ?  $this->sendResponse($response["message"], $response["data"]) : $this->sendError($response["message"], null, 400);
        } catch (\Exception $e) {
            Log::error('Updating attachment failed: ' . $e->getMessage());
            return $this->sendError('Une ereur est survenue pendant la mise à jour de la pièce jointe.', null, 500);
        }
    }

    public function destroy($id)
    {
        try {
            $attachment = Attachment::findOrFail($id);
            $attachment->delete();
            return $this->sendResponse('Pièce jointe supprimée avec succès.', null, 200);
        } catch (\Exception $e) {
            Log::error('Deleting attachment failed: ' . $e->getMessage());
            return $this->sendError('Echec de la suppression de la pièce jointe.', null, 500);
        }
    }

    public function updateAttachmentStatus(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'attachments' => 'required|array',
            'attachments.*.id' => 'required|integer|exists:attachments,id',
            'attachments.*.status' => 'required|in:SENT,VALIDATED,REJECTED',
            'attachments.*.message' => 'required_if:attachments.*.status,REJECTED|string',
        ]);

        if ($validator->fails()) {
            return $this->sendError("Un ou plusieurs des champs renseignés sont invalides.", $validator->errors(), 400);
        }

        $attachments = $request->input('attachments');

        try {
            foreach ($attachments as $attachmentData) {
                $attachment = Attachment::findOrFail($attachmentData['id']);
                $status = $attachmentData['status'];
                $message = $attachmentData['message'] ?? null;

                if ($status == 'VALIDATED') {
                    $allDocumentsValid = Document::where('attachment_id', $attachment->id)
                        ->where('status', '!=', 'VALID')
                        ->doesntExist();
                    if (!$allDocumentsValid) {
                        return $this->sendError("Impossible de valider la pièce jointe n° {$attachment->id} car tous ses documents n'ont pas été validés.", null, 500);
                    }
                }

                $attachment->update([
                    'status' => $status,
                    'message' => $status == 'REJECTED' ? $message : $attachment->message
                ]);
            }
            return $this->sendResponse('Pièces jointes modifiées avec succès.', null, 200);
        } catch (\Exception $e) {
            Log::error('Failed to update attachments status: ' . $e->getMessage());
            return $this->sendError("Nous n'avons pas pu mettre à jour le statut de l'une ou plusieurs des pièces jointes.", null, 500);
        }
    }
}
