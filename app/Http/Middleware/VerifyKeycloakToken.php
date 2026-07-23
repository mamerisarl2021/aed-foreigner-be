<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Auth\KeycloakJwtValidator;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class VerifyKeycloakToken
{
    public function __construct(
        private readonly KeycloakJwtValidator $validator,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('consul.keycloak.enabled')) {
            return $next($request);
        }

        $authorization = $request->header('Authorization', '');
        if (! str_starts_with($authorization, 'Bearer ')) {
            return response()->json([
                'success' => false,
                'message' => 'Token Keycloak requis.',
                'status' => 401,
            ], 401);
        }

        $token = trim(substr($authorization, 7));

        try {
            $payload = $this->validator->validate($token);
        } catch (\Throwable) {
            return response()->json([
                'success' => false,
                'message' => 'Token Keycloak invalide.',
                'status' => 401,
            ], 401);
        }

        $request->attributes->set('keycloak_payload', $payload);

        $email = $payload['email'] ?? $payload['preferred_username'] ?? null;
        if (is_string($email) && $email !== '') {
            $user = User::where('email', strtolower($email))->first();
            if ($user) {
                Auth::setUser($user);
            }
        }

        return $next($request);
    }
}
