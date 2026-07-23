<?php

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\AuthController;
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
*/

Route::group([], function () {
    Route::get('/health', HealthCheckController::class);

    Route::middleware(['auth:sanctum'])->get('/me', UserProfileController::class);

    Route::middleware(['auth:sanctum'])->group(function () {
        Route::middleware('role:administrateur_plateforme|client|agent|responsable_de_validation')->group(function () {
            Route::post('users/{id}', [UserController::class, 'update']);
        });

        Route::middleware('role:administrateur_plateforme')->group(function () {
            Route::post('/agents/register', [AuthController::class, 'registerAgent']);
            Route::post('/agents/{id}', [AuthController::class, 'updateAgent']);
            Route::delete('/agents/{id}', [AuthController::class, 'deleteAgent']);
            Route::get('/agents', [AuthController::class, 'listAgents']);
            Route::get('/agents/{id}', [AuthController::class, 'showAgent']);
        });

        Route::middleware('role:administrateur_plateforme|agent|auditeur|responsable_de_validation')->group(function () {
            Route::get('/audits', [AuditLogController::class, 'index']);
            Route::get('/stats', [StatsController::class, 'index']);
        });

        Route::middleware('role:agent|responsable_de_validation')->group(function () {
            Route::post('/management/users/update-status', [UserController::class, 'updateUserStatus']);
            Route::post('/clients/set-password', [UserController::class, 'setPassword']);
        });

        Route::get('/management/enrollment-reject-motifs', [EnrollmentRejectMotifController::class, 'index']);
        Route::get('/management/enrollment-stats', [EnrollmentStatsController::class, 'index']);
    });

    Route::middleware(['keycloak'])->group(function () {
        Route::post('/otp/send', [OtpController::class, 'send'])->middleware(['guest', 'transaction']);
        Route::post('/otp/verify', [OtpController::class, 'verify'])->middleware(['guest']);
        Route::post('/kyc/verify', [KycController::class, 'verify'])->middleware(['guest']);
        Route::post('/enrolements/etrangers', [EnrollmentController::class, 'storeEtranger'])->middleware(['guest']);
        Route::get('/enrolements/finalisation', [FinalisationController::class, 'show']);
        Route::post('/enrolements/{id}/finalisation', [FinalisationController::class, 'store']);

        Route::middleware(['auth:sanctum'])->group(function () {
            Route::get('/enrolements', [EnrollmentController::class, 'index']);
            Route::patch('/enrolements/{id}/instruction', [EnrollmentController::class, 'instruction']);
            Route::patch('/enrolements/{id}/validation', [EnrollmentController::class, 'validation']);
            Route::get('/enrolements/{id}', [EnrollmentController::class, 'show']);

            Route::prefix('enrolements/morales')->middleware('role:client')->group(function () {
                Route::post('/', [PersonneMoraleEnrollmentController::class, 'submit']);
                Route::get('/{id}', [PersonneMoraleEnrollmentController::class, 'show']);
                Route::post('/{id}/send-phone-otp', [PersonneMoraleEnrollmentController::class, 'sendPhoneOtp']);
                Route::post('/{id}/verify-phone-otp', [PersonneMoraleEnrollmentController::class, 'verifyPhoneOtp']);
            });
        });
    });

    Route::post('/enrolements/morales/{id}/verify-email', [PersonneMoraleEnrollmentController::class, 'verifyEmail']);

    Route::post('/clients/send-otp', [UserController::class, 'sendOtp'])->middleware(['guest', 'transaction']);
    Route::post('/clients/verify-otp', [UserController::class, 'verifyOtp'])->middleware(['guest']);
    Route::post('/clients/login', [UserController::class, 'login']);
    Route::post('/mobile/login', [UserController::class, 'loginMobile']);
    Route::post('/clients/password/link', [PasswordResetController::class, 'sendResetLink']);
    Route::post('/clients/password/reset', [PasswordResetController::class, 'resetPassword']);
    Route::post('/clients/some/reset', [PasswordResetController::class, 'resetSome']);

    Route::post('/admins/send-otp', [AuthController::class, 'sendOtp'])->middleware('guest');
    Route::post('/admins/verify-otp', [AuthController::class, 'verifyOtp']);
    Route::post('/admins/logout', [AuthController::class, 'logoutAdmin'])->middleware('auth');
    Route::post('/admin/login', [AuthController::class, 'loginAdmin']);
    Route::post('/admin/password/link', [AuthController::class, 'sendPasswordResetLink']);
    Route::post('/admin/password/reset', [AuthController::class, 'resetPassword']);

    Route::get('/decrypt/token/file/{filename}', [EncryptionController::class, 'decryptAndDisplay']);
    Route::get('users/search', [UserController::class, 'search']);
    Route::post('users-email/search', [UserController::class, 'searchPost']);
});
