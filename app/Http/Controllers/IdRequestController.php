<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\IdRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class IdRequestController extends BaseController
{
    public function index(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $idRequests = IdRequest::paginate(min((int) $request->get('perPage', 15), 100));

            $flattenedData = $idRequests->toArray();
            $data = $flattenedData['data'];
            unset($flattenedData['data']);

            $response = array_merge(['data' => $data], ['pagination' => $flattenedData]);

            return $this->sendPaginatedResponse('Liste des demandes ID.', $response);
        } catch (\Exception $e) {
            Log::error('Fetching ID requests failed: '.$e->getMessage());

            return $this->sendError('Fetching ID requests failed.', [], 500);
        }
    }

    public function show($id): \Illuminate\Http\JsonResponse
    {
        try {
            $idRequest = IdRequest::findOrFail($id);

            return $this->sendResponse('Détails de la demande ID.', $idRequest);
        } catch (\Exception $e) {
            Log::error('Fetching ID request failed: '.$e->getMessage());

            return $this->sendError('Fetching ID request failed.', [], 500);
        }
    }

    public function store(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validatedData = $request->validate([
                'type' => 'required',
                'status' => 'required',
                'structure_id' => 'required|exists:structures,id',
            ]);

            IdRequest::create($validatedData);

            return $this->sendResponse('ID request created successfully.');
        } catch (\Exception $e) {
            Log::error('Creating ID request failed: '.$e->getMessage());

            return $this->sendError('Creating ID request failed.', [], 500);
        }
    }

    public function update(Request $request, $id): \Illuminate\Http\JsonResponse
    {
        try {
            $validatedData = $request->validate([
                'type' => 'required',
                'status' => 'required',
                'structure_id' => 'required|exists:structures,id',
            ]);

            $idRequest = IdRequest::findOrFail($id);
            $idRequest->update($validatedData);

            return $this->sendResponse('ID request updated successfully.');
        } catch (\Exception $e) {
            Log::error('Updating ID request failed: '.$e->getMessage());

            return $this->sendError('Updating ID request failed.', [], 500);
        }
    }

    public function destroy($id): \Illuminate\Http\JsonResponse
    {
        try {
            $idRequest = IdRequest::findOrFail($id);
            $idRequest->delete();

            return $this->sendResponse('ID request deleted successfully.');
        } catch (\Exception $e) {
            Log::error('Deleting ID request failed: '.$e->getMessage());

            return $this->sendError('Deleting ID request failed.', [], 500);
        }
    }
}
