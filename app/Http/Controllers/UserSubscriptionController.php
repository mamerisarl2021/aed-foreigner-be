<?php

namespace App\Http\Controllers;

use App\Jobs\UserSubscribtionCreatedJob;
use App\Jobs\UserSubscribtionInitiatedJob;
use App\Jobs\UserSubscribtionValidatedJob;
use App\Models\Structure;
use App\Models\StructurePackage;
use App\Models\StructureSubscription;
use App\Models\UserPackage;
use App\Models\UserSubscription;
use App\Traits\ADTrait;
use App\Traits\AuthTrait;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Kkiapay\Kkiapay;
use Throwable;

class UserSubscriptionController extends BaseController
{
    use AuthTrait;
    use ADTrait;
    /**
     * Display a listing of the user subscriptions.
     */

    public function index(Request $request)
    {
        try {
            // Paginate the results with 10 items per page
            $structures = UserSubscription::paginate($request->get('perPage', 9999999999999));

            // Prepare the data without nested 'data' key to avoid duplication
            $flattenedData = $structures->toArray();
            $data = $flattenedData['data'];  // Extract the actual data
            unset($flattenedData['data']);   // Remove the nested data key

            // Merge the remaining pagination data with the actual data
            $response = array_merge(['data' => $data], ['pagination' => $flattenedData]);

            return $this->sendPaginatedResponse('Liste des abonnements.', $response);
        } catch (\Exception $e) {
            Log::error('Impossible de récupérer les abonnements des utilisateurs: ' . $e->getMessage());
            return $this->sendError('Impossible de récupérer les abonnements des utilisateurs.', null, 500);
        }
    }

    public function kkiaPayement(String $transId)
    {

        // $public_key = "9d0fc7a0649011ef9e4c8f724a020285";
        // $private_key = "tpk_9d0fc7a2649011ef9e4c8f724a020285";
        // $secret = "tsk_9d0fc7a3649011ef9e4c8f724a020285";

        $public_key = env('KKIA_PUBLIC_KEY');
        $private_key = env('KKIA_PRIVATE_KEY');
        $secret = env('KKIA_SECRET_KEY');
        $kkiapay = new Kkiapay(
            $public_key,
            $private_key,
            $secret,
            $sandbox = true
        );

        $payement = $kkiapay->verifyTransaction($transId);
        return collect($payement->state);
    }

    /**
     * Store a newly created user subscription in storage.
     */
    public function store(Request $request)
    {
        try {
            $request->validate([
                'user_id' => 'required|exists:users,id',
                'structure_id' => [
                    'sometimes',
                    function ($attribute, $value, $fail) use ($request) {
                        if ($request->type === 'EMPLOYEE' && is_null($value)) {
                            $fail('Le champ ' . $attribute . ' est requis quand la demande est de type employé.');
                        }
                    },
                    'exists:structures,id',
                ],
                'type' => ['required', Rule::in(['EMPLOYEE', 'CITIZEN'])],
                'transaction_id' => 'required|string'
            ]);
            if ($request->input('type') != 'EMPLOYEE') {
                $state = $this->kkiaPayement($request->input('transaction_id'));
                if (UserPackage::where('id', json_decode($state[0], true)['package'])->where('prix', (int)json_decode($state[0], true)['amount'])->count() != 1) {
                    Log::alert('Failed to create user subscription');
                    return $this->sendError('Ooops tentative de fraude détectée!', [], 500);
                }
            }
            DB::transaction(function () use ($request) {
                // Set all current subscriptions for this user and package type to false
                UserSubscription::where('user_id', $request->user_id)
                    ->where('type', $request->type)
                    ->update(['current' => false]);

                // Create new subscription with current set to true
                $subscriptionData = $request->all();
                $subscriptionData['current'] = true;
                $subscriptionData['status'] = 'SENT';
                $subscription = UserSubscription::create($subscriptionData);
                $user = $subscription->user;

                if ($request->type === 'CITIZEN'){
                    $updateStatus = $this->updateCertValidityByNPI($user->npi, $subscription->package->validity);
                    if (!$updateStatus['status']) {
                        Log::error('Failed to update user subscriptions: ' . $updateStatus['message'], $updateStatus);
                        throw new Exception('Failed to update user subscriptions.');
                    }
                    UserSubscribtionCreatedJob::dispatch(Auth::user()->email, $subscription->id);
                }else{
                    UserSubscribtionInitiatedJob::dispatch(Auth::user()->email, $subscription->id);
                }
            });
            return $this->sendResponse('Votre demande de création de certificat à bien été enregistrée, vous recevrez un mail sous peu pour les prochaines étapes.', $request);
        } catch (Throwable $e) {
            Log::error('Failed to create user subscription: ' . $e->getMessage());
            return $this->sendError($e->getMessage(), [$e], 400);
        }
    }

    /**
     * Display the specified user subscription.
     */
    public function show($id)
    {
        $subscription = UserSubscription::find($id);
        return $this->sendResponse('User subscription retrieved successfully.', $subscription);
    }

    /**
     * Remove the specified user subscription from storage.
     */
    public function destroy($id)
    {
        try {
            $subscription = UserSubscription::findOrFail($id);
            $subscription->delete();

            return $this->sendResponse('User subscription deleted successfully.');
        } catch (Throwable $e) {
            Log::error('Failed to delete user subscription: ' . $e->getMessage());
            return $this->sendError('Failed to delete user subscription.', [$e->getMessage()], 500);
        }
    }

    public function updateMultipleStatuses(Request $request)
    {
        $request->validate([
            'subscriptions' => 'required|array',
            'subscriptions.*.id' => 'required|exists:user_subscriptions,id',
            'subscriptions.*.status' => ['required', Rule::in(['SENT', 'TRAITEDBYSYSTEM', 'REJECTED', 'TRAITEDBYMANAGER', 'TRAITEDBYAGENT'])],
        ]);
        DB::beginTransaction();

        try {
            $updateStatusList = [];

            foreach ($request->subscriptions as $subscriptionData) {
                $subscription = UserSubscription::findOrFail($subscriptionData['id']);
                $struct = Structure::find($subscription->structure_id);

                if ($subscription->type == "EMPLOYEE" && $struct->manager_id != Auth::user()->id) {
                    Log::alert('Unauthorized access attempt by user ID: ' . Auth::user()->id . ' on structure ID: ' . $struct->id);
                    throw new Exception('Vous n\'êtes pas le manager de l\'entité ' . $struct->name . '. Cette tentative d\'escalade de privilège sera enregistrée.');
                }

                $remainingIdentities = $this->calculateRemainingIdentities($subscription->structure_id, $subscription->package->type);

                if ($subscription->type == "EMPLOYEE" && $remainingIdentities < 1) {
                    throw new Exception('Vous ne disposez plus d\'identité dans votre compte entité veuillez vous réabonner.');
                }

                if (in_array($subscriptionData['status'], ['TRAITEDBYSYSTEM', 'TRAITEDBYMANAGER', 'TRAITEDBYAGENT'])) {
                    // Set all current subscriptions for this user and package type to false
                    UserSubscription::where('user_id', $subscription->user_id)
                        ->where('type', $subscription->type)
                        ->update(['current' => false]);

                    // Set the current subscription to true
                    $subscriptionData['current'] = true;
                } else {
                    $subscriptionData['current'] = false;
                }

                $structure = $subscription->structure;
                $user = $subscription->user;

                if ($subscription->type == "EMPLOYEE" && $subscriptionData['status'] === 'TRAITEDBYMANAGER') {
                    $updateStatus = $this->updateUserAttributesByNPI($user->npi, "$structure->ifu|$structure->name|$structure->searchbase|E", $subscription->package->validity);
                    UserSubscribtionValidatedJob::dispatch($user->email, $subscription->id);
                    if (!$updateStatus['status']) {
                        Log::error('Failed to update user subscriptions: ' . $updateStatus['message'], $updateStatus);
                        throw new Exception('Failed to update user subscriptions.');
                    }
                }

                if ($subscription->type == "CITIZEN" && $subscriptionData['status'] === 'TRAITEDBYSYSTEM') {
                    // $updateStatus = $this->updateCertValidityByNPI($user->npi, $subscription->package->validity);
                    // if (!$updateStatus['status']) {
                    //     Log::error('Failed to update user subscriptions: ' . $updateStatus['message'], $updateStatus);
                    //     throw new Exception('Failed to update user subscriptions.');
                    // }
                }

                $subscription->update($subscriptionData);
                $updateStatusList[] = [
                    'subscription_id' => $subscription->id,
                    'status' => $subscriptionData['status'],
                    'current' => $subscriptionData['current']
                ];
            }
            DB::commit();
            return $this->sendResponse('User subscriptions updated successfully.', $updateStatusList);
        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Failed to update user subscriptions: ' . $e->getMessage(), $e->getTrace());
            return $this->sendError('Failed to update user subscriptions.', [$e->getMessage()], 500);
        }
    }

    // Calculate remaining identities for a structure
    private function calculateRemainingIdentities($structureId, $type)
    {
        $subscriptions = StructureSubscription::where('structure_id', $structureId)->whereHas('structurePackage', function ($query) use ($type) {
            $query->where('type', $type);
        })->get();
        $packagesIds = $subscriptions->pluck('structure_package_id');
        $totalIdentities = $this->calculateTotalIdentities($packagesIds);

        $usedIdentities = UserSubscription::where('structure_id', $structureId)
            ->where('type', $type)
            ->where('status', 'TRAITEDBYMANAGER')
            ->count();

        return $totalIdentities - $usedIdentities;
    }

    private function calculateTotalIdentities($packageIds)
    {
        $idCounts = array_count_values($packageIds->toArray());
        $packages = StructurePackage::whereIn('id', array_keys($idCounts))->get();
        $totalQuantity = 0;

        foreach ($packages as $package) {
            $totalQuantity += $package->quantity * $idCounts[$package->id];
        }

        return $totalQuantity;
    }

    public function packages()
    {
        try {
            $data = Cache::remember('packages_data', 60 * 60, function () {
                $userPackages = UserPackage::get(['id', 'prix', 'validity']);
                $structurePackages = StructurePackage::get(['id', 'prix', 'validity', 'quantity', 'type']);

                return [
                    'userPackages' => $userPackages,
                    'structurePackages' => $structurePackages
                ];
            });

            return $this->sendResponse('Liste des packages.', $data);
        } catch (\Exception $e) {
            Log::error('Fetching packages failed: ' . $e->getMessage());
            return $this->sendError('Échec de la récupération des packages.', [$e->getMessage()]);
        }
    }
}
