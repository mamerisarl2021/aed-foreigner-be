<?php

namespace App\Http\Controllers;

use App\Models\Identity;
use App\Models\OTP;
use App\Models\Revocation;
use App\Models\Signature;
use App\Models\SignatureDocument;
use App\Models\Structure;
use App\Models\StructureSubscription;
use App\Models\User;
use App\Models\UserSubscription;
use Carbon\Carbon;
use Illuminate\Http\Request;

class StatsController extends Controller
{
    public function index()
    {
        // Récupérer les statistiques des différents modèles
        $stats = [
            'total_users' => User::count(),
            'total_structures' => Structure::count(),
            'total_identities' => Identity::count(),
            'total_signatures' => Signature::count(),
            'total_signature_documents' => SignatureDocument::count(),
            'total_revocations' => Revocation::count(),
            'total_otps' => OTP::count(),
            'total_user_subscriptions' => UserSubscription::count(),
            'total_structure_subscriptions' => StructureSubscription::count(),
        ];

        // Récupérer les informations sur les rôles des utilisateurs
        $roles = [
            'admin' => User::role('admin')->count(),
            'client' => User::role('client')->count(),
            'tech_one' => User::role('tech_one')->count(),
            'tech_two' => User::role('tech_two')->count(),
            'tech_three' => User::role('tech_three')->count(),
        ];

        // Récupérer les informations sur les abonnements en cours
        $subscriptions = [
            'active_user_subscriptions' => UserSubscription::where('current', 1)->count(),
            'active_structure_subscriptions' => StructureSubscription::count(), // Pas de colonne 'status' ou 'current' dans StructureSubscription
        ];

        // Calculer le bénéfice total
        $total_revenue = UserSubscription::where('type', 'CITIZEN')
            ->with('userPackage')
            ->get()
            ->sum(function ($subscription) {
                return $subscription->userPackage ? $subscription->userPackage->prix : 0;
            });

        // Calculate package distribution for user subscriptions by 'type' and 'validity'
        $userPackageDistribution = UserSubscription::join('user_packages', 'user_subscriptions.package_id', '=', 'user_packages.id')
            ->select('user_packages.validity')
            ->groupBy('user_packages.validity')
            ->selectRaw('count(*) as count, user_packages.validity')
            ->get()
            ->map(function ($item) {
                return [
                    'type' => 'VID',
                    'validity' => $item->validity,
                    'count' => $item->count,
                ];
            });

        // Calculate package distribution for structure subscriptions by 'type' and 'validity'
        $structurePackageDistribution = StructureSubscription::join('structure_packages', 'structure_subscriptions.structure_package_id', '=', 'structure_packages.id')
            ->select('structure_packages.type', 'structure_packages.validity')
            ->groupBy('structure_packages.type', 'structure_packages.validity')
            ->selectRaw('count(*) as count, structure_packages.type, structure_packages.validity')
            ->get()
            ->map(function ($item) {
                return [
                    'type' => $item->type,
                    'validity' => $item->validity,
                    'count' => $item->count,
                ];
            });

        $total_revenue += StructureSubscription::with('structurePackage')
            ->get()
            ->sum(function ($subscription) {
                return $subscription->structurePackage ? $subscription->structurePackage->prix : 0;
            });

        // Générer des données de revenus et de dépenses par mois
        $earnings = [];
        $expenses = [];
        $months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

        foreach ($months as $index => $month) {
            $startOfMonth = Carbon::create(null, $index + 1, 1)->startOfMonth();
            $endOfMonth = Carbon::create(null, $index + 1, 1)->endOfMonth();

            $monthlyEarnings = UserSubscription::whereBetween('created_at', [$startOfMonth, $endOfMonth])->where('type', 'CITIZEN')
                ->with('userPackage')
                ->get()
                ->sum(function ($subscription) {
                    return $subscription->userPackage ? $subscription->userPackage->prix : 0;
                });

            $monthlyExpenses = StructureSubscription::whereBetween('created_at', [$startOfMonth, $endOfMonth])
                ->with('structurePackage')
                ->get()
                ->sum(function ($subscription) {
                    return $subscription->structurePackage ? $subscription->structurePackage->prix : 0;
                });

            $earnings[] = $monthlyEarnings;
            $expenses[] = $monthlyExpenses; // Les dépenses sont négatives
        }

        // Combiner toutes les statistiques
        $data = [
            'stats' => $stats,
            'roles' => $roles,
            'subscriptions' => $subscriptions,
            'total_revenue' => $total_revenue,
            'earnings' => $earnings,
            'expenses' => $expenses,
            'udistribution' => $userPackageDistribution,
            'sdistribution' => $structurePackageDistribution,
        ];

        return response()->json($data);
    }

    public function getStructureSubscriptions(Request $request)
    {
        // Start building the query
        $query = StructureSubscription::with(['structure', 'structurePackage']);

        // Filter by package type
        if ($request->has('type')) {
            $query->whereHas('structurePackage', function ($q) use ($request) {
                $q->where('type', 'LIKE', '%'.$request->input('type').'%');
            });
        }

        // Filter by structure name
        if ($request->has('user')) {
            $query->whereHas('structure', function ($q) use ($request) {
                $q->where('name', 'LIKE', '%'.$request->input('user').'%');
            });
        }

        // Calculate current month's revenue from 'structurePackage'
        $currentMonthRevenue = StructureSubscription::whereMonth('structure_subscriptions.created_at', now()->month)
            ->join('structure_packages', 'structure_subscriptions.structure_package_id', '=', 'structure_packages.id')
            ->sum('structure_packages.prix');

        // Calculate package distribution by 'type'
        $packageDistribution = StructureSubscription::join('structure_packages', 'structure_subscriptions.structure_package_id', '=', 'structure_packages.id')
            ->select('structure_packages.type')
            ->groupBy('structure_packages.type')
            ->whereMonth('structure_subscriptions.created_at', now()->month)
            ->selectRaw('count(*) as count, structure_packages.type')
            ->get()
            ->map(function ($item) {
                return [
                    'type' => $item->type,
                    'count' => $item->count,
                ];
            });

        // Set default pagination size or get it from the request
        $perPage = $request->input('per_page', 15);

        // Execute the query with pagination
        $subscriptions = $query->paginate($perPage);

        return response()->json([
            'subscriptions' => $subscriptions,
            'current_month_revenue' => $currentMonthRevenue,
            'package_distribution' => $packageDistribution,
        ]);
    }

    public function getUserSubscriptions(Request $request)
    {
        // Start building the query
        $query = UserSubscription::with(['user', 'structure', 'userPackage', 'structurePackage'])
            ->where('type', 'CITIZEN');

        // Apply filters on relationships
        if ($request->has('type')) {
            $query->whereHas('userPackage', function ($q) use ($request) {
                $q->where('type', 'LIKE', '%'.$request->input('type').'%');
            });
        }

        if ($request->has('user')) {
            $query->whereHas('structure', function ($q) use ($request) {
                $q->where('name', 'LIKE', '%'.$request->input('user').'%');
            });
        }

        if ($request->has('status')) {
            $query->where('status', 'LIKE', '%'.$request->input('status').'%');
        }

        // Calculate current month's revenue from 'userPackage'
        $currentMonthRevenue = UserSubscription::where('type', 'CITIZEN')
            ->whereMonth('user_subscriptions.created_at', now()->month)
            ->join('user_packages', 'user_subscriptions.package_id', '=', 'user_packages.id')
            ->sum('user_packages.prix');

        // Calculate package distribution by 'type'
        $packageDistribution = UserSubscription::join('user_packages', 'user_subscriptions.package_id', '=', 'user_packages.id')
            ->select('user_packages.validity')
            ->whereMonth('user_subscriptions.created_at', now()->month)
            ->groupBy('user_packages.validity')
            ->selectRaw('count(*) as count, user_packages.validity')
            ->get()
            ->map(function ($item) {
                return [
                    'type' => $item->validity,
                    'count' => $item->count,
                ];
            });

        // Set default pagination size or get it from the request
        $perPage = $request->input('per_page', 15);

        // Execute the query with pagination
        $subscriptions = $query->paginate($perPage);

        return response()->json([
            'subscriptions' => $subscriptions,
            'current_month_revenue' => $currentMonthRevenue,
            'package_distribution' => $packageDistribution,
        ]);
    }
}
