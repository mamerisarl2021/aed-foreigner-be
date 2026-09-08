<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Psceq\PsceqClientAuthService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsurePsceqApiKey
{
    public const CLIENT_ID_ATTRIBUTE = 'psceq_client_id';

    public const CLIENT_NAME_ATTRIBUTE = 'psceq_client_name';

    public const KEY_PREFIX_ATTRIBUTE = 'psceq_key_prefix';

    public function __construct(
        private readonly PsceqClientAuthService $auth,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $plain = $this->extractKey($request);
        $client = is_string($plain) ? $this->auth->authenticate($plain) : null;

        if ($client === null) {
            return response()->json([
                'success' => false,
                'message' => 'Clé API invalide.',
                'status' => 401,
            ], 401);
        }

        $request->attributes->set(self::CLIENT_ID_ATTRIBUTE, $client->id);
        $request->attributes->set(self::CLIENT_NAME_ATTRIBUTE, $client->name);
        $request->attributes->set(self::KEY_PREFIX_ATTRIBUTE, $client->key_prefix);

        return $next($request);
    }

    private function extractKey(Request $request): ?string
    {
        $header = $request->header('X-Api-Key');
        if (is_string($header) && $header !== '') {
            return $header;
        }

        $authorization = $request->header('Authorization', '');
        if (str_starts_with($authorization, 'Bearer ')) {
            $token = trim(substr($authorization, 7));

            return $token !== '' ? $token : null;
        }

        return null;
    }
}
