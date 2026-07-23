<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;

/**
 * @OA\Info(
 *      version="1.0.0",
 *      title="AED Foreigner API",
 *      description="API documentation for AED Foreigner Enrollment",
 *
 *      @OA\Contact(
 *          email="support@example.com"
 *      ),
 *
 *      @OA\License(
 *          name="Apache 2.0",
 *          url="http://www.apache.org/licenses/LICENSE-2.0.html"
 *      )
 * )
 *
 * @OA\Server(
 *      url="/",
 *      description="Relative to APP_URL (API prefix /api/v1 on routes)"
 * )
 *
 * @OA\SecurityScheme(
 *      securityScheme="sanctum",
 *      type="http",
 *      scheme="bearer",
 *      bearerFormat="Sanctum",
 *      description="Staff token from POST /api/v1/admin/login. Prefix: Bearer {token}"
 * )
 *
 * @OA\Tag(name="Enrollment - OTP", description="Diagram §2.2 — OTP send/verify")
 * @OA\Tag(name="Enrollment - KYC", description="Diagram §2.3 — sync KYC/liveness")
 * @OA\Tag(name="Enrollment - Physique", description="Diagram §2.4 — submit + review + finalisation")
 * @OA\Tag(name="Enrollment - Morale", description="Personne morale enrollment")
 */
class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;
}
