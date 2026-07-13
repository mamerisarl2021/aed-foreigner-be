<?php

namespace App\Http\Controllers;

use App\Models\IdRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class IdRequestController extends Controller
{
    public function index(Request $request)
    {
        try {
            $idRequests = IdRequest::paginate(min((int) $request->get('perPage', 15), 100));

            return response()->json($idRequests);
        } catch (\Exception $e) {
            Log::error('Fetching ID requests failed: '.$e->getMessage());

            return response()->json(['error' => 'Fetching ID requests failed.'], 500);
        }
    }

    public function show($id)
    {
        try {
            $idRequest = IdRequest::findOrFail($id);

            return response()->json($idRequest);
        } catch (\Exception $e) {
            Log::error('Fetching ID request failed: '.$e->getMessage());

            return response()->json(['error' => 'Fetching ID request failed.'], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'type' => 'required',
                'status' => 'required',
                'structure_id' => 'required|exists:structures,id',
            ]);

            IdRequest::create($validatedData);

            return response()->json(['message' => 'ID request created successfully.'], 201);
        } catch (\Exception $e) {
            Log::error('Creating ID request failed: '.$e->getMessage());

            return response()->json(['error' => 'Creating ID request failed.'], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $validatedData = $request->validate([
                'type' => 'required',
                'status' => 'required',
                'structure_id' => 'required|exists:structures,id',
            ]);

            $idRequest = IdRequest::findOrFail($id);
            $idRequest->update($validatedData);

            return response()->json(['message' => 'ID request updated successfully.'], 200);
        } catch (\Exception $e) {
            Log::error('Updating ID request failed: '.$e->getMessage());

            return response()->json(['error' => 'Updating ID request failed.'], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $idRequest = IdRequest::findOrFail($id);
            $idRequest->delete();

            return response()->json(['message' => 'ID request deleted successfully.'], 200);
        } catch (\Exception $e) {
            Log::error('Deleting ID request failed: '.$e->getMessage());

            return response()->json(['error' => 'Deleting ID request failed.'], 500);
        }
    }
}
