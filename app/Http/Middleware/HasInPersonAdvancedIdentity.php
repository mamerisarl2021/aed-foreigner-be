<?php

namespace App\Http\Middleware;

use App\Models\Identity;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HasInPersonAdvancedIdentity
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

        $hasInPersonAdvancedIdentity = Identity::where('user_id', $user->id)
            ->where('type', 'IN_PERSON')
            ->where('level', 'ADVANCED')
            ->whereIn('status', ['APPROVED', 'APPROVED_BY_AGENT'])
            ->exists();

        if (! $hasInPersonAdvancedIdentity) {
            // return response()->json([
            //     'message' => 'In-person advanced identity verification required'
            // ], 403);
            return response()->json(['message' => 'Vous devez avoir procédé à la vérification en face à face pour pouvoir acheter un token USB.', 'can_buy' => false], 403);

        }

        return $next($request);
    }
}
