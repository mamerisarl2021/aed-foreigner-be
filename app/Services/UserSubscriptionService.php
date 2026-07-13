<?php

namespace App\Services;

use App\Jobs\UserSubscriptionCreatedJob;
use App\Jobs\UserSubscriptionInitiatedJob;
use App\Jobs\UserSubscriptionValidatedJob;
use App\Models\Structure;
use App\Models\StructurePackage;
use App\Models\StructureSubscription;
use App\Models\UserPackage;
use App\Models\UserSubscription;
use App\Traits\ADTrait;
use App\Traits\AuthTrait;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Kkiapay\Kkiapay;
use Throwable;

class UserSubscriptionService
{
    use ADTrait;
    use AuthTrait;

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

    public function store(array $validatedData): ServiceResult
    {
        try {
            if ($validatedData['type'] != 'EMPLOYEE') {
                $state = $this->kkiaPayement($validatedData['transaction_id']);
                if (UserPackage::where('id', json_decode($state[0], true)['package'])->where('prix', (int) json_decode($state[0], true)['amount'])->count() != 1) {
                    Log::alert('Failed to create user subscription');

                    return ServiceResult::fail('Ooops tentative de fraude détectée!', [], 500);
                }
            }

            DB::transaction(function () use ($validatedData) {
                // Set all current subscriptions for this user and package type to false
                UserSubscription::where('user_id', $validatedData['user_id'])
                    ->where('type', $validatedData['type'])
                    ->update(['current' => false]);

                // Create new subscription with current set to true
                $subscriptionData = $validatedData;
                $subscriptionData['current'] = true;
                $subscriptionData['status'] = 'SENT';
                $subscription = UserSubscription::create($subscriptionData);
                $user = $subscription->user;

                if ($validatedData['type'] === 'CITIZEN') {
                    $updateStatus = $this->updateCertValidityByNPI($user->npi, $subscription->package->validity);
                    if (! $updateStatus['status']) {
                        Log::error('Failed to update user subscriptions: '.$updateStatus['message'], $updateStatus);
                        throw new Exception('Failed to update user subscriptions.');
                    }
                    UserSubscriptionCreatedJob::dispatch(Auth::user()->email, $subscription->id);
                } else {
                    UserSubscriptionInitiatedJob::dispatch(Auth::user()->email, $subscription->id);
                }
            });

            return ServiceResult::ok('Votre demande de création de certificat à bien été enregistrée, vous recevrez un mail sous peu pour les prochaines étapes.');
        } catch (Throwable $e) {
            Log::error('Failed to create user subscription: '.$e->getMessage());

            return ServiceResult::fail($e->getMessage(), [$e], 400);
        }
    }

    public function updateMultipleStatuses(array $subscriptions, int $authUserId): ServiceResult
    {
        DB::beginTransaction();

        try {
            $updateStatusList = [];

            foreach ($subscriptions as $subscriptionData) {
                $subscription = UserSubscription::findOrFail($subscriptionData['id']);
                $struct = Structure::find($subscription->structure_id);

                if ($subscription->type == 'EMPLOYEE' && $struct->manager_id != $authUserId) {
                    Log::alert('Unauthorized access attempt by user ID: '.$authUserId.' on structure ID: '.$struct->id);
                    throw new Exception('Vous n\'êtes pas le manager de l\'entité '.$struct->name.'. Cette tentative d\'escalade de privilège sera enregistrée.');
                }

                $remainingIdentities = $this->calculateRemainingIdentities($subscription->structure_id, $subscription->package->type);

                if ($subscription->type == 'EMPLOYEE' && $remainingIdentities < 1) {
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

                if ($subscription->type == 'EMPLOYEE' && $subscriptionData['status'] === 'TRAITEDBYMANAGER') {
                    $updateStatus = $this->updateUserAttributesByNPI($user->npi, "$structure->ifu|$structure->name|$structure->searchbase|E", $subscription->package->validity);
                    UserSubscriptionValidatedJob::dispatch($user->email, $subscription->id);
                    if (! $updateStatus['status']) {
                        Log::error('Failed to update user subscriptions: '.$updateStatus['message'], $updateStatus);
                        throw new Exception('Failed to update user subscriptions.');
                    }
                }

                if ($subscription->type == 'CITIZEN' && $subscriptionData['status'] === 'TRAITEDBYSYSTEM') {
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
                    'current' => $subscriptionData['current'],
                ];
            }
            DB::commit();

            return ServiceResult::ok('User subscriptions updated successfully.', $updateStatusList);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Failed to update user subscriptions: '.$e->getMessage(), $e->getTrace());

            return ServiceResult::fail('Failed to update user subscriptions.', [$e->getMessage()], 500);
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
}
