<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });
    }
    public function render($request, Throwable $exception)
    {
        $response = null;

        if ($exception instanceof UnauthorizedException) {
            $response = [
                'success' => false,
                'message' => "Vous n'avez pas les accès requis pour accéder à cette ressource",
                'status'  => 403,
            ];
            $response = response()->json($response, 403);
        } else {
            $response = parent::render($request, $exception);
        }

        return $response;
    }
}
