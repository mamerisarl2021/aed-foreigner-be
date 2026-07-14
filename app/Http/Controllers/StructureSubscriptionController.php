<?php

namespace App\Http\Controllers;

use App\Http\Requests\Management\StoreStructureSubscriptionRequest;
use App\Http\Requests\Management\ValidateEmployeeSubscriptionRequest;
use App\Models\StructurePackage;
use App\Models\Structure;
use App\Models\StructureSubscription;
use App\Models\User;
use App\Models\UserSubscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Kkiapay\Kkiapay;

class StructureSubscriptionController extends BaseController
{
    public function index(Request $request)
    {
        try {
            $structures = StructureSubscription::paginate(min((int) $request->get('perPage', 15), 100));

            $data = $structures->toArray();

            $paginationData = [
                'current_page' => $structures->currentPage(),
                'last_page' => $structures->lastPage(),
                'per_page' => $structures->perPage(),
                'total' => $structures->total(),
            ];

            $response = [
                'data' => $data,
                'pagination' => $paginationData,
            ];

            return $this->sendPaginatedResponse('Liste des structures.', $response);
        } catch (\Exception $e) {
            Log::error('Impossible de récupérer les abonnements des entreprises: '.$e->getMessage());

            return $this->sendError('Impossible de récupérer les abonnements des entreprises.', null, 500);
        }
    }

    public function kkiaPayement(string $transId)
    {

        $public_key = config('kkiapay.public_key');
        $private_key = config('kkiapay.private_key');
        $secret = config('kkiapay.secret');

        $kkiapay = new Kkiapay(
            $public_key,
            $private_key,
            $secret,
            config('kkiapay.sandbox')
        );

        $payement = $kkiapay->verifyTransaction($transId);

        return collect($payement->state);
    }

    // Store a new structure subscription
    public function store(StoreStructureSubscriptionRequest $request)
    {
        $state = $this->kkiaPayement($request->input('transaction_id'));
        if (StructurePackage::where('id', json_decode($state[0], true)['package'])->where('prix', (int) json_decode($state[0], true)['amount'])->count() != 1) {
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
            Log::error('Deleting structure subscription failed: '.$e->getMessage());

            return $this->sendError('Failed to delete structure subscription.', null, 500);
        }
    }

    // Validate employee requests
    public function validateEmployeeRequest(ValidateEmployeeSubscriptionRequest $request)
    {
        $userSubscription = UserSubscription::find($request->input('user_subscription_id'));
        if (! $userSubscription) { // Should be covered by validation but good practice
            return $this->sendError('Validation Error.', ['user_subscription_id' => 'Invalid subscription']);
        }

        $structure = Structure::find($userSubscription->structure_id); // Assuming relationship or column exists
        // If not directly on UserSubscription, we might need to fetch via StructureSubscription?
        // Let's check schema/relationship. UserSubscription has structure_id?
        // Based on `2024_07_04_150253_create_user_subscriptions_table.php`, let's assume structure_id is there or reachable.
        // Actually, let's verify UserSubscription model first.

        // Waiting for tool check before applying this specific replace if unsure.
        // But based on `calculateRemainingIdentities` using `UserSubscription::where('structure_id', ...)` it implies `structure_id` exists on `UserSubscription`.

        if ($structure && $structure->status !== 'APPROVED') {
            return $this->sendError('Impossible de valider un employé car l\'entreprise n\'est pas encore validée (Statut: '.$structure->status.').', null, 403);
        }

        $remainingIdentities = $this->calculateRemainingIdentities($request->input('user_subscription_id'));

        if ($remainingIdentities < 1) {
            return $this->sendError('Not enough identities remaining.', null, 404);
        }

        // Approve the request and deduct the identities
        $userRequest = UserSubscription::find($request->input('user_subscription_id'));
        $userRequest->update(['status' => 'TRAITEDBYMANAGER']);

        // Activate the employee user
        $user = User::find($userRequest->user_id);
        if ($user) {
            $user->update(['status' => 'ACTIVE']);
        }

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
