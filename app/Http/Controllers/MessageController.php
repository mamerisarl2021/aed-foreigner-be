<?php

namespace App\Http\Controllers;

use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MessageController extends Controller
{
    public function index(Request $request)
    {
        try {
            // Paginate the results with 10 items per page
            $messages = Message::paginate($request->get('perPage', 9999999999999));

            // Prepare the data without nested 'data' key to avoid duplication
            $flattenedData = $messages->toArray();
            $data = $flattenedData['data'];  // Extract the actual data
            unset($flattenedData['data']);   // Remove the nested data key

            // Merge the remaining pagination data with the actual data
            $response = array_merge(['data' => $data], ['pagination' => $flattenedData]);

            return $this->sendPaginatedResponse('Liste des messages.', $response);
        } catch (\Exception $e) {
            Log::error('Impossible de récupérer les messages: '.$e->getMessage());

            return $this->sendError('Impossible de récupérer les messages.', null, 500);
        }
    }

    public function show($id)
    {
        try {
            $message = Message::findOrFail($id);

            return response()->json($message);
        } catch (\Exception $e) {
            Log::error('Fetching message failed: '.$e->getMessage());

            return response()->json(['error' => 'Fetching message failed.'], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'content' => 'required|string',
                'user_id' => 'required|exists:users,id',
                'case_id' => 'required|exists:cases,id',
                'status' => 'required',
                // other validation rules
            ]);

            $message = Message::create($validatedData);

            return response()->json(['message' => 'Message created successfully.'], 201);
        } catch (\Exception $e) {
            Log::error('Creating message failed: '.$e->getMessage());

            return response()->json(['error' => 'Creating message failed.'], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $validatedData = $request->validate([
                'content' => 'required|string',
                'user_id' => 'required|exists:users,id',
                'case_id' => 'required|exists:cases,id',
                'status' => 'required',
                // other validation rules
            ]);

            $message = Message::findOrFail($id);
            $message->update($validatedData);

            return response()->json(['message' => 'Message updated successfully.'], 200);
        } catch (\Exception $e) {
            Log::error('Updating message failed: '.$e->getMessage());

            return response()->json(['error' => 'Updating message failed.'], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $message = Message::findOrFail($id);
            $message->delete();

            return response()->json(['message' => 'Message deleted successfully.'], 200);
        } catch (\Exception $e) {
            Log::error('Deleting message failed: '.$e->getMessage());

            return response()->json(['error' => 'Deleting message failed.'], 500);
        }
    }
}
