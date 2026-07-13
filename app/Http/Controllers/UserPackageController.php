<?php

namespace App\Http\Controllers;

use App\Models\UserPackage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class UserPackageController extends BaseController
{
    /**
     * Display a listing of the user packages.
     *
     * @return JsonResponse
     */
    public function index(Request $request)
    {
        try {
            // Paginate the results with 10 items per page
            $structures = UserPackage::paginate($request->get('perPage', 9999999999999));

            // Prepare the data without nested 'data' key to avoid duplication
            $flattenedData = $structures->toArray();
            $data = $flattenedData['data'];  // Extract the actual data
            unset($flattenedData['data']);   // Remove the nested data key

            // Merge the remaining pagination data with the actual data
            $response = array_merge(['data' => $data], ['pagination' => $flattenedData]);

            return $this->sendPaginatedResponse('Liste des packages utilisateurs.', $response);
        } catch (\Exception $e) {
            Log::error('Impossible de récupérer les packages utilisateurs: '.$e->getMessage());

            return $this->sendError('Impossible de récupérer les packages utilisateurs.', null, 500);
        }
    }

    /**
     * Store a newly created user package in storage.
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

        $userPackage = UserPackage::create($request->all());

        return $this->sendResponse('User package created successfully.', $userPackage);
    }

    /**
     * Display the specified user package.
     *
     * @param  int  $id
     * @return JsonResponse
     */
    public function show($id)
    {
        $userPackage = UserPackage::find($id);

        if (is_null($userPackage)) {
            return $this->sendError('User package not found.');
        }

        return $this->sendResponse('User package retrieved successfully.', $userPackage);
    }

    /**
     * Update the specified user package in storage.
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

        $userPackage = UserPackage::find($id);

        if (is_null($userPackage)) {
            return $this->sendError('User package not found.');
        }

        $userPackage->update($request->all());

        return $this->sendResponse('User package updated successfully.', $userPackage);
    }

    /**
     * Remove the specified user package from storage.
     *
     * @param  int  $id
     * @return JsonResponse
     */
    public function destroy($id)
    {
        $userPackage = UserPackage::find($id);

        if (is_null($userPackage)) {
            return $this->sendError('User package not found.');
        }

        $userPackage->delete();

        return $this->sendResponse('User package deleted successfully.');
    }
}
