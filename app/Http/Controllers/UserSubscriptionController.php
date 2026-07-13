<?php

namespace App\Http\Controllers;

use App\Models\StructurePackage;
use App\Models\UserPackage;
use App\Models\UserSubscription;
use App\Services\UserSubscriptionService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Throwable;

class UserSubscriptionController extends BaseController
{
    public function __construct(
        private readonly UserSubscriptionService $subscriptionService,
    ) {}

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
        } catch (Exception $e) {
            Log::error('Impossible de récupérer les abonnements des utilisateurs: '.$e->getMessage());

            return $this->sendError('Impossible de récupérer les abonnements des utilisateurs.', null, 500);
        }
    }

    /**
     * Store a newly created user subscription in storage.
     */
    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'user_id' => 'required|exists:users,id',
            'structure_id' => [
                'sometimes',
                function ($attribute, $value, $fail) use ($request) {
                    if ($request->type === 'EMPLOYEE' && is_null($value)) {
                        $fail('Le champ '.$attribute.' est requis quand la demande est de type employé.');
                    }
                },
                'exists:structures,id',
            ],
            'type' => ['required', Rule::in(['EMPLOYEE', 'CITIZEN'])],
            'transaction_id' => 'required|string',
        ]);

        $result = $this->subscriptionService->store($validatedData);

        if (! $result->success) {
            return $this->sendError($result->message, $result->data ?? [], $result->code);
        }

        return $this->sendResponse($result->message, $request);
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
            Log::error('Failed to delete user subscription: '.$e->getMessage());

            return $this->sendError('Failed to delete user subscription.', [$e->getMessage()], 500);
        }
    }

    public function updateMultipleStatuses(Request $request)
    {
        $validatedData = $request->validate([
            'subscriptions' => 'required|array',
            'subscriptions.*.id' => 'required|exists:user_subscriptions,id',
            'subscriptions.*.status' => ['required', Rule::in(['SENT', 'TRAITEDBYSYSTEM', 'REJECTED', 'TRAITEDBYMANAGER', 'TRAITEDBYAGENT'])],
        ]);

        $authUserId = (int) $request->user()->id;

        $result = $this->subscriptionService->updateMultipleStatuses($validatedData['subscriptions'], $authUserId);

        if (! $result->success) {
            return $this->sendError($result->message, $result->data ?? [], $result->code);
        }

        return $this->sendResponse($result->message, $result->data ?? []);
    }

    public function packages()
    {
        try {
            $data = Cache::remember('packages_data', 60 * 60, function () {
                $userPackages = UserPackage::get(['id', 'prix', 'validity']);
                $structurePackages = StructurePackage::get(['id', 'prix', 'validity', 'quantity', 'type']);

                return [
                    'userPackages' => $userPackages,
                    'structurePackages' => $structurePackages,
                ];
            });

            return $this->sendResponse('Liste des packages.', $data);
        } catch (Exception $e) {
            Log::error('Fetching packages failed: '.$e->getMessage());

            return $this->sendError('Échec de la récupération des packages.', [$e->getMessage()]);
        }
    }
}
