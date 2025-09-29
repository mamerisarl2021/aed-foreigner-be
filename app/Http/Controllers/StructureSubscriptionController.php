<?php

namespace App\Http\Controllers;

use App\Models\StructurePackage;
use App\Models\StructureSubscription;
use App\Models\UserSubscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Kkiapay\Kkiapay;


class StructureSubscriptionController extends BaseController
{
    public function index(Request $request)
    {
        try {
            $structures = StructureSubscription::paginate($request->get('perPage', 9999999999999));

            $data = $structures->toArray();

            $paginationData = [
                'current_page' => $structures->currentPage(),
                'last_page' => $structures->lastPage(),
                'per_page' => $structures->perPage(),
                'total' => $structures->total(),
            ];

            $response = [
                'data' => $data,
                'pagination' => $paginationData
            ];

            return $this->sendPaginatedResponse('Liste des structures.', $response);
        } catch (\Exception $e) {
            Log::error('Impossible de récupérer les abonnements des entreprises: ' . $e->getMessage());
            return $this->sendError('Impossible de récupérer les abonnements des entreprises.', null, 500);
        }
    }

    public function kkiaPayement(String $transId)
    {

        $public_key = env('KKIA_PUBLIC_KEY');
        $private_key = env('KKIA_PRIVATE_KEY');
        $secret = env('KKIA_SECRET_KEY');

        // $public_key = "9d0fc7a0649011ef9e4c8f724a020285";
        // $private_key = "tpk_9d0fc7a2649011ef9e4c8f724a020285";
        // $secret = "tsk_9d0fc7a3649011ef9e4c8f724a020285";

        $kkiapay = new Kkiapay(
            $public_key,
            $private_key,
            $secret,
            $sandbox = true
        );

        $payement = $kkiapay->verifyTransaction($transId);
        return collect($payement->state);
    }

    // Store a new structure subscription
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'structure_id' => 'required|exists:structures,id',
            'structure_package_id' => 'required|exists:structure_packages,id',
            'transaction_id' => 'required',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors());
        }

        $state = $this->kkiaPayement($request->input('transaction_id'));
        if (StructurePackage::where('id', json_decode($state[0], true)['package'])->where('prix', (int)json_decode($state[0], true)['amount'])->count() != 1) {
            Log::alert('Failed to create user subscription');
            return $this->sendError('Ooops tentative de fraude détectée!', [], 500);
        }

        $structureSubscription = StructureSubscription::create($request->all());
        return $this->sendResponse('Structure subscription created successfully.', $structureSubscription);
    }

    // Show a specific structure subscription
    public function show($id)
    {
        $structureSubscription = StructureSubscription::find($id);

        if (is_null($structureSubscription)) {
            return $this->sendError('Structure subscription not found.');
        }

        return $this->sendResponse('Structure subscription retrieved successfully.', $structureSubscription);
    }

    // Delete a specific structure subscription
    public function destroy($id)
    {
        try {
            $structureSubscription = StructureSubscription::findOrFail($id);
            $structureSubscription->delete();
            return $this->sendResponse('Structure subscription deleted successfully.');
        } catch (\Exception $e) {
            Log::error('Deleting structure subscription failed: ' . $e->getMessage());
            return $this->sendError('Failed to delete structure subscription.', null, 500);
        }
    }

    // Validate employee requests
    public function validateEmployeeRequest(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_subscription_id' => 'required|exists:user_subscriptions,id',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors());
        }

        $remainingIdentities = $this->calculateRemainingIdentities($request->input('user_subscription_id'));

        if ($remainingIdentities < 1) {
            return $this->sendError('Not enough identities remaining.', null, 404);
        }

        // Approve the request and deduct the identities
        $userRequest = UserSubscription::find($request->input('user_subscription_id'));
        $userRequest->update(["status" => 'TRAITEDBYMANAGER']);

        return $this->sendResponse('Employee request validated successfully.', $userRequest);
    }

    // Calculate remaining identities for a structure
    private function calculateRemainingIdentities($structureId)
    {
        $subscriptions = StructureSubscription::where('structure_id', $structureId)->get();
        $totalIdentities = $subscriptions->sum('quantity');

        $usedIdentities = UserSubscription::where('structure_id', $structureId)
            ->where('status', 'TRAITEDBYMANAGER')
            ->count();

        return $totalIdentities - $usedIdentities;
    }
}
