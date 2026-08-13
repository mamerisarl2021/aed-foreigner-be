<?php

use App\Http\Controllers\AdminActivityLogController;
use App\Http\Controllers\AdminEnrolledPersonController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ClientSecurityController;
use App\Http\Controllers\EncryptionController;
use App\Http\Controllers\EnrollmentController;
use App\Http\Controllers\EnrollmentRejectMotifController;
use App\Http\Controllers\EnrollmentStatsController;
use App\Http\Controllers\FinalisationController;
use App\Http\Controllers\KycController;
use App\Http\Controllers\OtpController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\PersonneMoraleEnrollmentController;
use App\Http\Controllers\Singletons\HealthCheckController;
use App\Http\Controllers\Singletons\UserProfileController;
use App\Http\Controllers\StatsController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

/*
| API routes — loaded by bootstrap/app.php with the "api" middleware group.
| Authenticated resource actions rely on policies / gates (§9.3), not role: middleware.
*/

Route::group([], function () {
    Route::get('/health', HealthCheckController::class);

    Route::middleware(['auth:sanctum'])->get('/me', UserProfileController::class);

    Route::middleware(['auth:sanctum'])->group(function () {
        Route::post('/clients/logout', [ClientSecurityController::class, 'logout']);
        Route::post('/clients/password/change', [ClientSecurityController::class, 'changePassword'])
            ->middleware('throttle:password-reset');
        Route::post('/clients/pin/change', [ClientSecurityController::class, 'changePin'])
            ->middleware('throttle:password-reset');
        Route::get('/clients/security-questions', [ClientSecurityController::class, 'showSecurityQuestions']);
        Route::put('/clients/security-questions', [ClientSecurityController::class, 'updateSecurityQuestions'])
            ->middleware('throttle:password-reset');

        Route::post('users/{id}', [UserController::class, 'update']);

        Route::post('/agents/register', [AuthController::class, 'registerAgent']);
        Route::post('/agents/{id}', [AuthController::class, 'updateAgent']);
        Route::delete('/agents/{id}', [AuthController::class, 'deleteAgent']);
        Route::get('/agents', [AuthController::class, 'listAgents']);
        Route::get('/agents/{id}', [AuthController::class, 'showAgent']);

        Route::get('/admin/enrolled-persons', [AdminEnrolledPersonController::class, 'index']);
        Route::get('/admin/enrolled-persons/{id}', [AdminEnrolledPersonController::class, 'show']);
        Route::post('/clients/set-password', [UserController::class, 'setPassword']);

        Route::get('/admin/activity-logs', [AdminActivityLogController::class, 'index']);
        Route::get('/admin/activity-logs/{id}', [AdminActivityLogController::class, 'show']);
        Route::get('/audits', [AuditLogController::class, 'index']);

        Route::post('/admin/enrollment-reject-motifs', [EnrollmentRejectMotifController::class, 'store']);
        Route::get('/admin/enrollment-reject-motifs/{id}', [EnrollmentRejectMotifController::class, 'show']);
        Route::patch('/admin/enrollment-reject-motifs/{id}', [EnrollmentRejectMotifController::class, 'update']);
        Route::delete('/admin/enrollment-reject-motifs/{id}', [EnrollmentRejectMotifController::class, 'destroy']);

        Route::get('/stats', [StatsController::class, 'index']);
        Route::get('/users/search', [UserController::class, 'search']);
        Route::post('/users-email/search', [UserController::class, 'searchPost']);
        Route::get('/decrypt/token/file/{filename}', [EncryptionController::class, 'decryptAndDisplay']);
        Route::post('/management/users/update-status', [UserController::class, 'updateUserStatus']);

        Route::get('/management/enrollment-reject-motifs', [EnrollmentRejectMotifController::class, 'index']);
        Route::get('/management/enrollment-stats', [EnrollmentStatsController::class, 'index']);
    });

    Route::middleware(['keycloak'])->group(function () {
        Route::post('/otp/send', [OtpController::class, 'send'])->middleware(['guest', 'transaction', 'throttle:otp-send']);
        Route::post('/otp/verify', [OtpController::class, 'verify'])->middleware(['guest', 'throttle:otp-verify']);
        Route::post('/kyc/document/read', [KycController::class, 'readDocument'])->middleware(['guest', 'throttle:document-read']);
        Route::post('/kyc/verify', [KycController::class, 'verify'])->middleware(['guest']);
        Route::post('/enrolements/etrangers', [EnrollmentController::class, 'storeEtranger'])->middleware(['guest']);
        Route::get('/enrolements/finalisation', [FinalisationController::class, 'show']);
        Route::post('/enrolements/finalisation/otp/send', [FinalisationController::class, 'sendOtp'])
            ->middleware(['guest', 'throttle:otp-send']);
        Route::post('/enrolements/finalisation/otp/verify', [FinalisationController::class, 'verifyOtp'])
            ->middleware(['guest', 'throttle:otp-verify']);
        Route::post('/enrolements/{id}/finalisation', [FinalisationController::class, 'store'])->middleware(['throttle:password-reset']);

        Route::middleware(['auth:sanctum'])->group(function () {
            Route::get('/enrolements', [EnrollmentController::class, 'index']);
            Route::patch('/enrolements/{id}/prise-en-charge', [EnrollmentController::class, 'priseEnCharge']);
            Route::patch('/enrolements/{id}/prise-en-charge-validation', [EnrollmentController::class, 'priseEnChargeValidation']);
            Route::patch('/enrolements/{id}/instruction', [EnrollmentController::class, 'instruction']);
            Route::patch('/enrolements/{id}/validation', [EnrollmentController::class, 'validation']);
            Route::get('/enrolements/{id}', [EnrollmentController::class, 'show']);

            // Personne morale ownership is enforced by EnrollmentRequestPolicy.
            Route::prefix('enrolements/morales')->group(function () {
                Route::post('/', [PersonneMoraleEnrollmentController::class, 'submit']);
                Route::get('/{id}', [PersonneMoraleEnrollmentController::class, 'show']);
                Route::post('/{id}/send-phone-otp', [PersonneMoraleEnrollmentController::class, 'sendPhoneOtp'])->middleware('throttle:otp-send');
                Route::post('/{id}/verify-phone-otp', [PersonneMoraleEnrollmentController::class, 'verifyPhoneOtp'])->middleware('throttle:otp-verify');
            });
        });
    });

    Route::post('/enrolements/morales/{id}/verify-email', [PersonneMoraleEnrollmentController::class, 'verifyEmail'])->middleware('throttle:otp-verify');

    Route::post('/clients/send-otp', [UserController::class, 'sendOtp'])->middleware(['guest', 'transaction', 'throttle:otp-send']);
    Route::post('/clients/verify-otp', [UserController::class, 'verifyOtp'])->middleware(['guest', 'throttle:otp-verify']);
    Route::post('/clients/login', [UserController::class, 'login'])->middleware('throttle:auth-login');
    Route::post('/mobile/login', [UserController::class, 'loginMobile'])->middleware('throttle:auth-login');
    Route::post('/clients/password/link', [PasswordResetController::class, 'sendResetLink'])->middleware('throttle:password-reset');
    Route::post('/clients/password/reset', [PasswordResetController::class, 'resetPassword'])->middleware('throttle:password-reset');
    Route::post('/clients/some/reset', [PasswordResetController::class, 'resetSome'])->middleware('throttle:password-reset');

    Route::post('/admins/logout', [AuthController::class, 'logoutAdmin'])->middleware('auth:sanctum');
    Route::post('/admin/login', [AuthController::class, 'loginAdmin'])->middleware('throttle:auth-login');
    Route::post('/admin/login/keycloak', [AuthController::class, 'loginAdminKeycloak'])->middleware('throttle:auth-login');
    Route::post('/admin/password/link', [AuthController::class, 'sendPasswordResetLink'])->middleware('throttle:password-reset');
    Route::post('/admin/password/reset', [AuthController::class, 'resetPassword'])->middleware('throttle:password-reset');
    Route::post('/admin/password/change', [AuthController::class, 'changePassword'])->middleware('auth:sanctum');
});
