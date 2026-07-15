<?php

namespace App\Http\Controllers;

use App\Models\Stamp;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class StampController extends BaseController
{
    // Define relationships if needed
    protected $stampRelationship = ['user'];

    // Fetch all stamps for a specific user
    public function index(Request $request)
    {
        $user_id = $request->user()->id;
        try {
            $stamps = Stamp::withCount($this->stampRelationship)
                ->whereUserId($user_id)
                ->orderByDesc('id')
                ->get()
                ->load($this->stampRelationship);

            return $this->sendResponse('Cachets récupérées avec succès', $stamps);
        } catch (\Exception $e) {
            return $this->sendError('Une erreur s\'est produite lors de la récupération des preuves.');
        }
    }

    // Store a new stamp
    public function store(Request $request)
    {
        $request->validate([
            'type' => 'required|string',
            'fichier' => 'required|mimes:png,jpg,jpeg',
        ]);

        try {
            $type = $request->type;

            $path = Storage::cloud()->put('stamps', $request->file('fichier'));

            // Save the stamp to the database
            $stamp = new Stamp([
                'user_id' => $request->user()->id,
                'fichier' => $path,
                'type' => $type,
            ]);
            $stamp->save();

            return $this->sendResponse('Cachet créé avec succès', $stamp);
        } catch (QueryException $exception) {
            return $this->sendError('Une erreur s\'est produite lors de la création de la preuve.');
        }
    }

    // Get a specific stamp by ID
    public function show(int $id)
    {
        try {
            $stamp = Stamp::with($this->stampRelationship)->findOrFail($id);

            return $this->sendResponse('Cachet récupérée avec succès', $stamp);
        } catch (ModelNotFoundException $e) {
            return $this->sendError('Une erreur s\'est produite lors de la récupération de la preuve.');
        }
    }

    // Update an existing stamp
    public function update(int $id, Request $request)
    {
        $request->validate([
            'type' => 'required|string',
            'fichier' => 'required|mimes:png,jpg,jpeg',
        ]);

        try {
            $stamp = Stamp::findOrFail($id);
            $type = $request->type;

            $path = Storage::cloud()->put('stamps', $request->file('fichier'));

            // Delete the old file
            $this->deleteFileSpecificFolder($stamp->fichier);

            // Update the stamp in the database
            $stamp->update([
                'user_id' => $request->user()->id,
                'fichier' => $path,
                'type' => $type,
            ]);

            return $this->sendResponse('Cachet modifiée avec succès', $stamp);
        } catch (\Exception $e) {
            Log::error('Error while updating stamp', ['error' => json_encode($e)]);

            return $this->sendError('Une erreur s\'est produite lors de la modification de la preuve.');
        }
    }

    // Delete a specific stamp by ID
    public function destroy(int $id)
    {
        try {
            $stamp = Stamp::findOrFail($id);
            // Delete the file from storage
            $this->deleteFileSpecificFolder($stamp->fichier);
            // Delete the stamp from the database
            $stamp->delete();

            return $this->sendResponse('Cachet supprimée avec succès', null);
        } catch (ModelNotFoundException $e) {
            return $this->sendError('Cachet non trouvée.');
        } catch (\Exception $e) {
            return $this->sendError('Une erreur s\'est produite lors de la suppression de la preuve.');
        }
    }

    // Helper method to delete a file in a specific folder
    protected function deleteFileSpecificFolder(string $name)
    {
        if (Storage::cloud()->exists($name)) {
            Storage::cloud()->delete($name);
        }

        return 'file not found';
    }
}
