<?php

namespace App\Http\Controllers;

use App\Http\Requests\Management\UpdateAttachmentStatusRequest;
use App\Models\Attachment;
use App\Models\Document;
use App\Traits\AttachmentTrait;
use App\Traits\AuditTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AttachmentController extends BaseController
{
    use AttachmentTrait;
    use AuditTrait;

    /**
     * @OA\Get(
     *      path="/api/attachments/{id}",
     *      operationId="getAttachment",
     *      tags={"Attachments"},
     *      summary="Get Attachment Details",
     *      security={{"sanctum":{}}},
     *
     *      @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *
     *      @OA\Response(response=200, description="Success")
     * )
     */
    public function show($id)
    {
        try {
            $attachment = Attachment::findOrFail($id);

            return $this->sendResponse(
                'Pièce jointe',
                [
                    'piece' => $attachment,
                ]
            );
        } catch (\Exception $e) {
            Log::error('Fetching attachment failed: '.$e->getMessage());

            return response()->json(['error' => 'Problème lors de la récupération de la pièce jointe.'], 500);
        }
    }

    /**
     * @OA\Post(
     *      path="/api/entities/attachments",
     *      operationId="createAttachment",
     *      tags={"Attachments"},
     *      summary="Upload Attachment",
     *      security={{"sanctum":{}}},
     *
     *      @OA\RequestBody(
     *          required=true,
     *
     *          @OA\MediaType(
     *              mediaType="multipart/form-data",
     *
     *              @OA\Schema(
     *                  required={"name", "structure_id", "status", "message"},
     *
     *                  @OA\Property(property="name", type="string"),
     *                  @OA\Property(property="structure_id", type="integer"),
     *                  @OA\Property(property="status", type="string"),
     *                  @OA\Property(property="message", type="string"),
     *                  @OA\Property(property="files[]", type="array", @OA\Items(type="string", format="binary"))
     *              )
     *          )
     *      ),
     *
     *      @OA\Response(response=200, description="Upload success")
     * )
     */
    public function store(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'name' => 'required|string|max:255',
                'structure_id' => 'required|exists:structures,id',
                'status' => 'required',
                'message' => 'required|string',
                'files.*' => 'required|mimes:pdf,docx,doc,xls,mp4,png,jpeg,jpg|max:2048',
            ]);

            $attachment = Attachment::create($validatedData);
            $response = $this->attachFiles($request->file('files'), $attachment['id']);

            return $response['status'] ? $this->sendResponse($response['message'], $response['data']) : $this->sendError($response['message'], null, 400);
        } catch (\Exception $e) {
            Log::error('Creating attachment failed: '.$e->getMessage());

            return $this->sendError('Une ereur est survenue pendant la création de la pièce jointe.', null, 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $validatedData = $request->validate([
                'name' => 'required|string|max:255',
                'files.*' => 'required|mimes:pdf,docx,doc,xls,mp4,png,jpeg,jpg',
            ]);

            $attachment = Attachment::findOrFail($id);
            $attachment->update($validatedData);
            $response = $this->attachFiles($request->file('files'), $id);

            return $response['status'] ? $this->sendResponse($response['message'], $response['data']) : $this->sendError($response['message'], null, 400);
        } catch (\Exception $e) {
            Log::error('Updating attachment failed: '.$e->getMessage());

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
            Log::error('Deleting attachment failed: '.$e->getMessage());

            return $this->sendError('Echec de la suppression de la pièce jointe.', null, 500);
        }
    }

    /**
     * @OA\Post(
     *      path="/api/management/attachments/update-status",
     *      operationId="updateAttachmentStatus",
     *      tags={"Management"},
     *      summary="Update Attachment Status (Bulk)",
     *      security={{"sanctum":{}}},
     *
     *      @OA\RequestBody(
     *          required=true,
     *
     *          @OA\JsonContent(
     *              required={"attachments"},
     *
     *              @OA\Property(property="attachments", type="array", @OA\Items(
     *                  @OA\Property(property="id", type="integer"),
     *                  @OA\Property(property="status", type="string", enum={"SENT", "VALIDATED", "REJECTED"}),
     *                  @OA\Property(property="message", type="string", nullable=true)
     *              ))
     *          )
     *      ),
     *
     *      @OA\Response(response=200, description="Success")
     * )
     */
    public function updateAttachmentStatus(UpdateAttachmentStatusRequest $request)
    {
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
                    if (! $allDocumentsValid) {
                        return $this->sendError("Impossible de valider la pièce jointe n° {$attachment->id} car tous ses documents n'ont pas été validés.", null, 500);
                    }
                }

                $attachment->update([
                    'status' => $status,
                    'message' => $status == 'REJECTED' ? $message : $attachment->message,
                ]);
            }

            return $this->sendResponse('Pièces jointes modifiées avec succès.', null, 200);
        } catch (\Exception $e) {
            Log::error('Failed to update attachments status: '.$e->getMessage());

            return $this->sendError("Nous n'avons pas pu mettre à jour le statut de l'une ou plusieurs des pièces jointes.", null, 500);
        }
    }
}
