<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\TransactionException;
use Closure;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class WithTransaction
{
    public function handle(Request $request, Closure $next): Response
    {
        return rescue(
            function () use ($request, $next) {
                return DB::transaction(function () use ($request, $next) {
                    $response = $next($request);
                    // If the response has an exception or is not a successful response, rollback.
                    if (isset($response->exception) && $response->getStatusCode() >= 400) {
                        throw new TransactionException($response->exception->getMessage() ?? 'An error occurred.');
                    } elseif ($response->getStatusCode() >= 400) {
                        throw new TransactionException('An error occurred.');
                    }

                    return $response;
                });
            },
            function (Throwable $e) use ($request, $next) {
                Log::error('Rolled back transaction on error', [
                    'error' => [
                        'msg' => 'Here is the error',
                        'traces' => $e->getTrace(),
                    ],
                    'url' => $request->fullUrl(),
                ]);

                return $next($request);
            }
        );
    }
}
