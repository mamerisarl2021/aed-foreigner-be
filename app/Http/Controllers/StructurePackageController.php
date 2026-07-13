<?php

namespace App\Http\Controllers;

use App\Models\StructurePackage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class StructurePackageController extends BaseController
{
    /**
     * Display a listing of the structurepackage packages.
     *
     * @return JsonResponse
     */
    public function index(Request $request)
    {
        try {
            // Paginate the results with 10 items per page
            $structures = StructurePackage::paginate(min((int) $request->get('perPage', 15), 100));

            // Prepare the data without nested 'data' key to avoid duplication
            $flattenedData = $structures->toArray();
            $data = $flattenedData['data'];  // Extract the actual data
            unset($flattenedData['data']);   // Remove the nested data key

            // Merge the remaining pagination data with the actual data
            $response = array_merge(['data' => $data], ['pagination' => $flattenedData]);

            return $this->sendPaginatedResponse('Liste des packages entreprise.', $response);
        } catch (\Exception $e) {
            Log::error('Impossible de récupérer les packages entreprise: '.$e->getMessage());

            return $this->sendError('Impossible de récupérer les packages entreprise.', null, 500);
        }
    }

    /**
     * Store a newly created structurepackage package in storage.
     *
     * @return JsonResponse
     */
    public function store(Request $request)
    {
        $request->validate([
            'prix' => 'required|integer',
            'validity' => 'required|integer',
            'quantity' => 'required|integer',
            'type' => 'required|in:EPF,VID,TOKEN',
        ]);

        $structurePackage = StructurePackage::create($request->all());

        return $this->sendResponse('StructurePackage package created successfully.', $structurePackage);
    }

    /**
     * Display the specified structurepackage package.
     *
     * @param  int  $id
     * @return JsonResponse
     */
    public function show($id)
    {
        $structurePackage = StructurePackage::find($id);

        if (is_null($structurePackage)) {
            return $this->sendError('StructurePackage package not found.');
        }

        return $this->sendResponse('StructurePackage package retrieved successfully.', $structurePackage);
    }

    /**
     * Update the specified structurepackage package in storage.
     *
     * @param  int  $id
     * @return JsonResponse
     */
    public function update(Request $request, $id)
    {
        $request->validate([
            'prix' => 'integer',
            'validity' => 'integer',
            'quantity' => 'integer',
            'type' => 'in:EPF,VID,TOKEN',
        ]);

        $structurePackage = StructurePackage::find($id);

        if (is_null($structurePackage)) {
            return $this->sendError('StructurePackage package not found.');
        }

        $structurePackage->update($request->all());

        return $this->sendResponse('StructurePackage package updated successfully.', $structurePackage);
    }

    /**
     * Remove the specified structurepackage package from storage.
     *
     * @param  int  $id
     * @return JsonResponse
     */
    public function destroy($id)
    {
        $structurePackage = StructurePackage::find($id);

        if (is_null($structurePackage)) {
            return $this->sendError('StructurePackage package not found.');
        }

        $structurePackage->delete();

        return $this->sendResponse('StructurePackage package deleted successfully.');
    }
}
