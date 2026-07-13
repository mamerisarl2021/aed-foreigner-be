<?php

namespace App\Http\Middleware;

use App\Models\Identity;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HasAdvancedIdentity
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $hasAdvancedIdentity = Identity::where('user_id', $user->id)
            ->where('level', 'ADVANCED')
            ->whereIn('status', ['APPROVED', 'APPROVED_BY_AGENT'])
            ->exists();

        if (! $hasAdvancedIdentity) {
            return response()->json(['message' => 'Votre demande d\'identité doit au préalable avoir été validée pour que vous puissiez acheter un certificat.', 'can_buy' => false], 403);
        }

        return $next($request);
    }
}
