<?php

namespace App\Http\Controllers;

use App\Models\Cases;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CaseController extends BaseController
{
    public function index(Request $request)
    {
        try {
            // Paginate the results with 10 items per page
            $cases = Cases::paginate(min((int) $request->get('perPage', 15), 100));

            // Prepare the data without nested 'data' key to avoid duplication
            $flattenedData = $cases->toArray();
            $data = $flattenedData['data'];  // Extract the actual data
            unset($flattenedData['data']);   // Remove the nested data key

            // Merge the remaining pagination data with the actual data
            $response = array_merge(['data' => $data], ['pagination' => $flattenedData]);

            return $this->sendPaginatedResponse('Liste des cases.', $response);
        } catch (\Exception $e) {
            Log::error('Impossible de récupérer les cases: '.$e->getMessage());

            return $this->sendError('Impossible de récupérer les cases.', null, 500);
        }
    }

    public function show($id)
    {
        try {
            $case = Cases::findOrFail($id);

            return response()->json($case);
        } catch (\Exception $e) {
            Log::error('Fetching case failed: '.$e->getMessage());

            return response()->json(['error' => 'Fetching case failed.'], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'titre' => 'required|string|max:255',
                'type' => 'required|string',
                'status' => 'required',
                'user_id' => 'required|exists:users,id',
            ]);

            $case = Cases::create($validatedData);

            return response()->json(['message' => 'Case created successfully.'], 201);
        } catch (\Exception $e) {
            Log::error('Creating case failed: '.$e->getMessage());

            return response()->json(['error' => 'Creating case failed.'], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $validatedData = $request->validate([
                'titre' => 'required|string|max:255',
                'type' => 'required|string',
                'status' => 'required',
                'user_id' => 'required|exists:users,id',
                // other validation rules
            ]);

            $case = Cases::findOrFail($id);
            $case->update($validatedData);

            return response()->json(['message' => 'Case updated successfully.'], 200);
        } catch (\Exception $e) {
            Log::error('Updating case failed: '.$e->getMessage());

            return response()->json(['error' => 'Updating case failed.'], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $case = Cases::findOrFail($id);
            $case->delete();

            return response()->json(['message' => 'Case deleted successfully.'], 200);
        } catch (\Exception $e) {
            Log::error('Deleting case failed: '.$e->getMessage());

            return response()->json(['error' => 'Deleting case failed.'], 500);
        }
    }
}
