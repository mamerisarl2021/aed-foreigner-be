<?php

use App\Http\Controllers\AdminActivityLogController;
use App\Http\Controllers\AdminEnrolledCompanyController;
use App\Http\Controllers\AdminEnrolledPersonController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ClientSecurityController;
use App\Http\Controllers\EncryptionController;
use App\Http\Controllers\EnrollmentController;
use App\Http\Controllers\EnrollmentRejectMotifController;
use App\Http\Controllers\EnrollmentStatsController;
use App\Http\Controllers\EnrollmentTrackingController;
use App\Http\Controllers\FinalisationController;
use App\Http\Controllers\KycController;
use App\Http\Controllers\ManagerEnrollmentController;
use App\Http\Controllers\OtpController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\PersonneMoraleEnrollmentController;
use App\Http\Controllers\Singletons\HealthCheckController;
use App\Http\Controllers\Singletons\UserProfileController;
use App\Http\Controllers\StaffAgentController;
use App\Http\Controllers\StaffDirectoryController;
use App\Http\Controllers\StatsController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

/*
| API routes — loaded by bootstrap/app.php with the "api" middleware group.
| Authenticated resource actions rely on policies / gates (§9.3), not role: middleware.
*/

Route::group([], function () {
    Route::get('/health', HealthCheckController::class)->name('health');

    Route::middleware(['auth:sanctum'])->get('/me', UserProfileController::class)->name('me');

    Route::middleware(['auth:sanctum'])->group(function () {
        Route::post('/clients/logout', [ClientSecurityController::class, 'logout'])->name('clients.logout');
        Route::post('/clients/password/change', [ClientSecurityController::class, 'changePassword'])
            ->middleware('throttle:password-reset')
            ->name('clients.password.change');
        Route::post('/clients/pin/change', [ClientSecurityController::class, 'changePin'])
            ->middleware('throttle:password-reset')
            ->name('clients.pin.change');
        Route::get('/clients/security-questions', [ClientSecurityController::class, 'showSecurityQuestions'])
            ->name('clients.security-questions.show');
        Route::put('/clients/security-questions', [ClientSecurityController::class, 'updateSecurityQuestions'])
            ->middleware('throttle:password-reset')
            ->name('clients.security-questions.update');

        // Personne morale étape 2 : document d'identité seul (ni selfie ni liveness).
        // Sanctum comme le reste du parcours morale — /kyc/verify reste la porte physique.
        Route::post('/kyc/document/verify', [KycController::class, 'verifyDocument'])
            ->middleware('throttle:document-verify')
            ->name('kyc.document.verify');

        // Personne morale ownership is enforced by EnrollmentRequestPolicy.
        Route::prefix('enrolements/morales')->name('enrolements.morales.')->group(function () {
            Route::get('/', [PersonneMoraleEnrollmentController::class, 'index'])->name('index');
            Route::post('/', [PersonneMoraleEnrollmentController::class, 'submit'])->name('store');
            Route::get('/{id}', [PersonneMoraleEnrollmentController::class, 'show'])->name('show');
            Route::put('/{id}', [PersonneMoraleEnrollmentController::class, 'correct'])->name('update');
            Route::post('/{id}/send-phone-otp', [PersonneMoraleEnrollmentController::class, 'sendPhoneOtp'])
                ->middleware('throttle:otp-send')
                ->name('send-phone-otp');
            Route::post('/{id}/verify-phone-otp', [PersonneMoraleEnrollmentController::class, 'verifyPhoneOtp'])
                ->middleware('throttle:otp-verify')
                ->name('verify-phone-otp');
        });

        Route::post('users/{id}', [UserController::class, 'update'])->name('users.update');

        Route::post('/agents/register', [StaffAgentController::class, 'register'])->name('agents.register');
        Route::post('/agents/{id}', [StaffAgentController::class, 'update'])->name('agents.update');
        Route::delete('/agents/{id}', [StaffAgentController::class, 'destroy'])->name('agents.destroy');
        Route::get('/agents', [StaffAgentController::class, 'index'])->name('agents.index');
        Route::get('/agents/{id}', [StaffAgentController::class, 'show'])->name('agents.show');

        Route::get('/admin/enrolled-persons', [AdminEnrolledPersonController::class, 'index'])->name('admin.enrolled-persons.index');
        Route::get('/admin/enrolled-persons/{id}', [AdminEnrolledPersonController::class, 'show'])->name('admin.enrolled-persons.show');
        // Pendant morale de enrolled-persons, qui ne connaît que les physiques.
        Route::get('/admin/enrolled-companies', [AdminEnrolledCompanyController::class, 'index'])->name('admin.enrolled-companies.index');
        Route::get('/admin/enrolled-companies/{id}', [AdminEnrolledCompanyController::class, 'show'])
            ->whereUuid('id')
            ->name('admin.enrolled-companies.show');
        Route::post('/clients/set-password', [StaffDirectoryController::class, 'setClientPassword'])->name('clients.set-password');

        Route::get('/admin/activity-logs', [AdminActivityLogController::class, 'index'])->name('admin.activity-logs.index');
        Route::get('/admin/activity-logs/{id}', [AdminActivityLogController::class, 'show'])->name('admin.activity-logs.show');
        Route::get('/audits', [AuditLogController::class, 'index'])->name('audits.index');

        Route::post('/admin/enrollment-reject-motifs', [EnrollmentRejectMotifController::class, 'store'])->name('admin.enrollment-reject-motifs.store');
        Route::get('/admin/enrollment-reject-motifs/{id}', [EnrollmentRejectMotifController::class, 'show'])->name('admin.enrollment-reject-motifs.show');
        Route::patch('/admin/enrollment-reject-motifs/{id}', [EnrollmentRejectMotifController::class, 'update'])->name('admin.enrollment-reject-motifs.update');
        Route::delete('/admin/enrollment-reject-motifs/{id}', [EnrollmentRejectMotifController::class, 'destroy'])->name('admin.enrollment-reject-motifs.destroy');

        Route::get('/stats', [StatsController::class, 'index'])->name('stats.index');
        Route::get('/users/search', [StaffDirectoryController::class, 'search'])->name('users.search');
        Route::post('/users-email/search', [StaffDirectoryController::class, 'searchByEmail'])->name('users.search-by-email');
        Route::get('/decrypt/token/file/{filename}', [EncryptionController::class, 'decryptAndDisplay'])->name('decrypt.token.file');
        Route::post('/management/users/update-status', [StaffDirectoryController::class, 'updateStatus'])->name('management.users.update-status');

        Route::get('/management/enrollment-reject-motifs', [EnrollmentRejectMotifController::class, 'index'])->name('management.enrollment-reject-motifs.index');
        Route::get('/management/enrollment-stats', [EnrollmentStatsController::class, 'index'])->name('management.enrollment-stats.index');
        Route::get('/management/enrolements/physiques', [ManagerEnrollmentController::class, 'indexPhysiques'])->name('management.enrolements.physiques.index');
        Route::get('/management/enrolements/physiques/{id}', [ManagerEnrollmentController::class, 'showPhysique'])->name('management.enrolements.physiques.show');
        Route::get('/management/enrolements/morales', [ManagerEnrollmentController::class, 'indexMorales'])->name('management.enrolements.morales.index');
        Route::get('/management/enrolements/morales/{id}', [ManagerEnrollmentController::class, 'showMorale'])->name('management.enrolements.morales.show');

        // Agent/responsable queue — Sanctum only. Do not nest under `keycloak`
        // (infra gateway JWT). Staff BO sends the Sanctum token from
        // POST /admin/login/keycloak; KEYCLOAK_ENABLED is the guest/gateway flag.
        Route::get('/enrolements', [EnrollmentController::class, 'index'])->name('enrolements.index');
        Route::patch('/enrolements/{id}/prise-en-charge', [EnrollmentController::class, 'priseEnCharge'])
            ->whereUuid('id')
            ->name('enrolements.prise-en-charge');
        Route::patch('/enrolements/{id}/prise-en-charge-validation', [EnrollmentController::class, 'priseEnChargeValidation'])
            ->whereUuid('id')
            ->name('enrolements.prise-en-charge-validation');
        Route::patch('/enrolements/{id}/instruction', [EnrollmentController::class, 'instruction'])
            ->whereUuid('id')
            ->name('enrolements.instruction');
        Route::patch('/enrolements/{id}/validation', [EnrollmentController::class, 'validation'])
            ->whereUuid('id')
            ->name('enrolements.validation');
        Route::get('/enrolements/{id}', [EnrollmentController::class, 'show'])
            ->whereUuid('id')
            ->name('enrolements.show');
    });

    Route::middleware(['keycloak'])->group(function () {
        Route::post('/otp/send', [OtpController::class, 'send'])
            ->middleware(['guest', 'transaction', 'throttle:otp-send'])
            ->name('otp.send');
        Route::post('/otp/verify', [OtpController::class, 'verify'])
            ->middleware(['guest', 'throttle:otp-verify'])
            ->name('otp.verify');
        Route::post('/kyc/document/read', [KycController::class, 'readDocument'])
            ->middleware(['throttle:document-read'])
            ->name('kyc.document.read');
        Route::post('/kyc/verify', [KycController::class, 'verify'])->name('kyc.verify');
        Route::post('/enrolements/etrangers', [EnrollmentController::class, 'storeEtranger'])
            ->middleware(['guest'])
            ->name('enrolements.etrangers.store');
        Route::post('/enrolements/suivi', [EnrollmentTrackingController::class, 'show'])
            ->middleware('throttle:enrollment-suivi')
            ->name('enrolements.suivi');
        Route::get('/enrolements/finalisation', [FinalisationController::class, 'show'])->name('enrolements.finalisation.show');
        Route::post('/enrolements/finalisation/otp/send', [FinalisationController::class, 'sendOtp'])
            ->middleware(['throttle:otp-send'])
            ->name('enrolements.finalisation.otp.send');
        Route::post('/enrolements/finalisation/otp/verify', [FinalisationController::class, 'verifyOtp'])
            ->middleware(['throttle:otp-verify'])
            ->name('enrolements.finalisation.otp.verify');
        Route::post('/enrolements/finalisation', [FinalisationController::class, 'store'])
            ->middleware(['throttle:password-reset'])
            ->name('enrolements.finalisation.store');
    });

    Route::post('/enrolements/morales/{id}/verify-email', [PersonneMoraleEnrollmentController::class, 'verifyEmail'])
        ->middleware('throttle:otp-verify')
        ->name('enrolements.morales.verify-email');

    Route::post('/clients/send-otp', [UserController::class, 'sendOtp'])
        ->middleware(['guest', 'transaction', 'throttle:otp-send'])
        ->name('clients.send-otp');
    Route::post('/clients/verify-otp', [UserController::class, 'verifyOtp'])
        ->middleware(['guest', 'throttle:otp-verify'])
        ->name('clients.verify-otp');
    Route::post('/clients/login', [UserController::class, 'login'])
        ->middleware('throttle:auth-login')
        ->name('clients.login');
    Route::post('/mobile/login', [UserController::class, 'loginMobile'])
        ->middleware('throttle:auth-login')
        ->name('mobile.login');
    Route::post('/clients/password/link', [PasswordResetController::class, 'sendResetLink'])
        ->middleware('throttle:password-reset')
        ->name('clients.password.link');
    Route::post('/clients/password/reset', [PasswordResetController::class, 'resetPassword'])
        ->middleware('throttle:password-reset')
        ->name('clients.password.reset');
    Route::post('/clients/some/reset', [PasswordResetController::class, 'resetSome'])
        ->middleware('throttle:password-reset')
        ->name('clients.some.reset');

    Route::post('/admins/logout', [AuthController::class, 'logoutAdmin'])
        ->middleware('auth:sanctum')
        ->name('admins.logout');
    Route::post('/admin/logout', [AuthController::class, 'logoutAdmin'])
        ->middleware('auth:sanctum')
        ->name('admin.logout');
    Route::post('/admin/login', [AuthController::class, 'loginAdmin'])
        ->middleware('throttle:auth-login')
        ->name('admin.login');
    Route::post('/admin/login/keycloak', [AuthController::class, 'loginAdminKeycloak'])
        ->middleware('throttle:auth-login')
        ->name('admin.login.keycloak');
    Route::post('/admin/password/link', [AuthController::class, 'sendPasswordResetLink'])
        ->middleware('throttle:password-reset')
        ->name('admin.password.link');
    Route::post('/admin/password/reset', [AuthController::class, 'resetPassword'])
        ->middleware('throttle:password-reset')
        ->name('admin.password.reset');
    Route::post('/admin/password/change', [AuthController::class, 'changePassword'])
        ->middleware('auth:sanctum')
        ->name('admin.password.change');
});
