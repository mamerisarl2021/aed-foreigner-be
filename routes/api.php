<?php

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\EncryptionController;
use App\Http\Controllers\EnrollmentRejectMotifController;
use App\Http\Controllers\EnrollmentStatsController;
use App\Http\Controllers\ForeignerEnrollmentController;
use App\Http\Controllers\IdentityReviewController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\StatsController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

/*
| API routes — loaded by bootstrap/app.php with the "api" middleware group.
*/

Route::group([], function () {
    Route::get('/health', \App\Http\Controllers\Singletons\HealthCheckController::class);

    Route::middleware(['auth:sanctum'])->get('/me', \App\Http\Controllers\Singletons\UserProfileController::class);

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

        Route::prefix('management/identity-reviews')->group(function () {
            Route::get('/', [IdentityReviewController::class, 'index']);
            Route::get('/{id}', [IdentityReviewController::class, 'show']);
            Route::post('/{id}/claim', [IdentityReviewController::class, 'claim']);
            Route::post('/{id}/approve', [IdentityReviewController::class, 'approve']);
            Route::post('/{id}/reject', [IdentityReviewController::class, 'reject']);
            Route::post('/{id}/visio/request', [IdentityReviewController::class, 'requestVisio']);
            Route::post('/{id}/visio/complete', [IdentityReviewController::class, 'completeVisio']);
            Route::post('/{id}/supervisor/approve', [IdentityReviewController::class, 'supervisorApprove']);
            Route::post('/{id}/supervisor/reject', [IdentityReviewController::class, 'supervisorReject']);
            Route::post('/{id}/supervisor/return', [IdentityReviewController::class, 'supervisorReturn']);
        });
    });

    Route::post('/clients/send-otp', [UserController::class, 'sendOtp'])->middleware(['guest', 'transaction']);
    Route::post('/clients/verify-otp', [UserController::class, 'verifyOtp'])->middleware(['guest']);
    Route::post('/clients/login', [UserController::class, 'login']);
    Route::post('/mobile/login', [UserController::class, 'loginMobile']);
    Route::post('/clients/password/link', [PasswordResetController::class, 'sendResetLink']);
    Route::post('/clients/password/reset', [PasswordResetController::class, 'resetPassword']);
    Route::post('/clients/all/reset', [PasswordResetController::class, 'resetAll']);
    Route::post('/clients/some/reset', [PasswordResetController::class, 'resetSome']);

    Route::post('/foreigner/send-otp', [ForeignerEnrollmentController::class, 'sendOtp'])->middleware(['guest', 'transaction']);
    Route::post('/foreigner/verify-otp', [ForeignerEnrollmentController::class, 'verifyOtp'])->middleware(['guest']);
    Route::post('/foreigner/enroll', [ForeignerEnrollmentController::class, 'submitEnrollment'])->middleware(['guest']);

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
