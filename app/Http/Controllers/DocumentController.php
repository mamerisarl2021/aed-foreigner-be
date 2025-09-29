<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Document;
use App\Traits\AttachmentTrait;
use App\Traits\EncryptionTrait;
use Illuminate\Support\Facades\Log;

class DocumentController extends BaseController
{
    use EncryptionTrait;
    use AttachmentTrait;

    public function index(Request $request)
    {
        try {
            // Paginate the results with 10 items per page
            $documents = Document::paginate($request->get('perPage', 9999999999999));

            // Prepare the data without nested 'data' key to avoid duplication
            $flattenedData = $documents->toArray();
            $data = $flattenedData['data'];  // Extract the actual data
            unset($flattenedData['data']);   // Remove the nested data key

            // Merge the remaining pagination data with the actual data
            $response = array_merge(['data' => $data], ['pagination' => $flattenedData]);

            return $this->sendPaginatedResponse('Liste des documents.', $response);
        } catch (\Exception $e) {
            Log::error('Impossible de récupérer les documents: ' . $e->getMessage());
            return $this->sendError('Impossible de récupérer les documents.', null, 500);
        }
    }

    public function show($id)
    {
        try {
            $document = Document::findOrFail($id);
            return response()->json($document);
        } catch (\Exception $e) {
            Log::error('Fetching document failed: ' . $e->getMessage());
            return response()->json(['error' => 'Fetching document failed.'], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'attachment_id' => 'required|exists:attachments,id',
                'file' => 'required|mimes:pdf,docx,doc,xls,mp4,png,jpeg,jpg|max:2048'
            ]);
            $file = $request->file('file');
            $response = $this->attachFiles([$file], $validatedData["attachment_id"]);
            return $response['status'] ?  $this->sendResponse($response["message"], $response["data"]) : $this->sendError($response["message"], null, 400);
        } catch (\Exception $e) {
            Log::error('Creating document failed: ' . $e->getMessage());
            return $this->sendError("Creating document failed.", null, 400);
        }
    }

    public function updateDocumentStatus(Request $request)
    {
        $request->validate([
            'documents' => 'required|array',
            'documents.*.id' => 'required|integer|exists:documents,id',
            'documents.*.status' => 'required|in:VALID,INVALID,PENDING',
        ]);

        $documents = $request->input('documents');

        try {
            foreach ($documents as $doc) {
                Document::where('id', $doc['id'])->update(['status' => $doc['status']]);
            }

            return $this->sendResponse("Documents updated successfully.", null);
        } catch (\Exception $e) {
            Log::error('Failed to update documents status: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to update documents status.'], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $document = Document::findOrFail($id);
            $document->delete();
            return $this->sendResponse("Document deleted successfully.", null);
        } catch (\Exception $e) {
            Log::error('Deleting document failed: ' . $e->getMessage());
            return $this->sendError("Deleting document failed.", null, 400);
        }
    }
}
