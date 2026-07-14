<?php

use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CaseController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\EncryptionController;
use App\Http\Controllers\ForeignerEnrollmentController;
use App\Http\Controllers\IdentityReviewController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\RevocationController;
use App\Http\Controllers\SignatureController;
use App\Http\Controllers\SignatureDocumentController;
use App\Http\Controllers\SigningIdentityController;
use App\Http\Controllers\StampController;
use App\Http\Controllers\StatsController;
use App\Http\Controllers\StructureController;
use App\Http\Controllers\StructurePackageController;
use App\Http\Controllers\StructureSubscriptionController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UserPackageController;
use App\Http\Controllers\UserSubscriptionController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
| API routes — loaded by bootstrap/app.php with the "api" middleware group.
*/

Route::group([], function () {
    Route::get('/health', \App\Http\Controllers\Singletons\HealthCheckController::class);

    Route::middleware(['auth:sanctum'])->get('/me', \App\Http\Controllers\Singletons\UserProfileController::class);

    Route::resource('user-packages', UserPackageController::class)->only([
        'index',
        'show',
    ]);
    Route::resource('structure-packages', StructurePackageController::class)->only([
        'index',
        'show',
    ]);

    Route::middleware(['auth:sanctum'])->group(function () {
        Route::get('structures', [StructureController::class, 'index']);

        Route::apiResource('attachments', AttachmentController::class);
        Route::apiResource('documents', DocumentController::class);
        Route::apiResource('cases', CaseController::class);
        Route::apiResource('messages', MessageController::class);
        Route::apiResource('user-subscriptions', UserSubscriptionController::class);
        // Route::apiResource('user-packages', UserPackageController::class);
        Route::apiResource('structure-subscriptions', StructureSubscriptionController::class);
        // Route::apiResource('structure-packages', StructurePackageController::class);

        Route::middleware('role:admin|client|tech_one|tech_two|tech_three|superviseur')->group(function () {
            Route::post('users/{id}', [UserController::class, 'update']);
            Route::get('search/structures', [StructureController::class, 'search']);
            Route::get('packages', [UserSubscriptionController::class, 'packages']);
            Route::get('structures/{id}', [StructureController::class, 'show']);
        });

        Route::middleware('role:admin')->group(function () {
            // AGENTS MANAGEMENT ROUTES
            Route::post('/agents/register', [AuthController::class, 'registerAgent']);
            Route::post('/agents/{id}', [AuthController::class, 'updateAgent']);
            Route::delete('/agents/{id}', [AuthController::class, 'deleteAgent']);
            Route::get('/agents', [AuthController::class, 'listAgents']);
            Route::get('/agents/{id}', [AuthController::class, 'showAgent']);
        });

        Route::middleware('role:admin|tech_one|tech_two|tech_three|auditeur|superviseur')->group(function () {
            // AGENTS MANAGEMENT ROUTES
            Route::get('/audits', [AuditLogController::class, 'index']);
            Route::get('/stats', [StatsController::class, 'index']);
            Route::get('/incomming/citizen', [StatsController::class, 'getUserSubscriptions']);
            Route::get('/incomming/structures', [StatsController::class, 'getStructureSubscriptions']);

            Route::resource('user-packages', UserPackageController::class)->only([
                'store',
                'update',
                'destroy',
            ]);
            Route::resource('structure-packages', StructurePackageController::class)->only([
                'store',
                'update',
                'destroy',
            ]);
        });

        Route::middleware('role:tech_one|tech_two|tech_three|superviseur')->group(function () {
            // MANAGEMENT ROUTES
            Route::post('/management/documents/update-status', [DocumentController::class, 'updateDocumentStatus']);
            Route::post('/management/structures/update-status', [StructureController::class, 'updateStructureStatus']);
            Route::post('/management/structures/notify-admin', [StructureController::class, 'sendOtp']);
            Route::post('/management/attachments/update-status', [AttachmentController::class, 'updateAttachmentStatus']);
            Route::post('/management/users/update-status', [UserController::class, 'updateUserStatus']);
            Route::post('/management/users/identity-status', [UserController::class, 'updateIdentityStatus']);
            Route::get('/management/identities', [UserController::class, 'identities']);
            Route::get('/management/identities/{id}', [UserController::class, 'identity']);

            Route::post('/identity/reschedule', [UserController::class, 'rescheduleIdentity']);
            Route::post('/identity/approve-date', [UserController::class, 'approveIdentityDate']);
            Route::post('/identity/approve', [UserController::class, 'updateInPersonIdentityStatus']);

            // Identity review list/view for agents & supervisors
            Route::get('/management/identity-reviews', [IdentityReviewController::class, 'index']);
            Route::get('/management/identity-reviews/{id}', [IdentityReviewController::class, 'show']);

            // Agent actions
            Route::middleware('role:tech_one|tech_two|tech_three')->group(function () {
                Route::post('/management/identity-reviews/{id}/claim', [IdentityReviewController::class, 'claim']);
                Route::post('/management/identity-reviews/{id}/approve', [IdentityReviewController::class, 'approve']);
                Route::post('/management/identity-reviews/{id}/reject', [IdentityReviewController::class, 'reject']);
            });

            // Supervisor actions
            Route::middleware('role:superviseur')->group(function () {
                Route::post('/management/identity-reviews/{id}/supervisor/approve', [IdentityReviewController::class, 'supervisorApprove']);
                Route::post('/management/identity-reviews/{id}/supervisor/reject', [IdentityReviewController::class, 'supervisorReject']);
            });

            Route::get('users', [UserController::class, 'index']);

            Route::post('/clients/set-password', [UserController::class, 'setPassword']);

            Route::get('revocations', [RevocationController::class, 'index']);
        });

        Route::middleware('role:client')->group(function () {
            // CLIENTS MANAGEMENT ROUTES
            Route::post('/clients/link-entity', [StructureController::class, 'store']);
            Route::post('/clients/one-shot-link', [StructureController::class, 'oneShotStore']);
            Route::get('/entities/mine', [StructureController::class, 'mine']);
            Route::get('/revocations/mine', [RevocationController::class, 'mine']);
            Route::post('/management/structures/verify-admin', [StructureController::class, 'verifyOtp']);

            // ENTITIES ATTACHMENTS MANAGEMENT ROUTES
            Route::post('/entities/attachments', [AttachmentController::class, 'store']);
            Route::post('/entities/attachments/{id}', [AttachmentController::class, 'update']);
            Route::delete('/entities/attachments/{id}', [AttachmentController::class, 'destroy']);

            // ATTACHMENTS DOCUMENTS MANAGEMENT ROUTES
            Route::post('/attachments/documents', [DocumentController::class, 'store']);
            Route::delete('/attachments/documents/{id}', [DocumentController::class, 'destroy']);

            Route::post('/signatures', [SignatureDocumentController::class, 'store']);
            Route::post('/signatures/simple', [SignatureDocumentController::class, 'simpleStore']);
            Route::post('/signatures/verify', [SignatureDocumentController::class, 'verify']);
            Route::post('/signatures/invite/{userId}/on/{documentId}', [SignatureController::class, 'store']);
            Route::post('/signatures/invite/{documentId}', [SignatureController::class, 'storeMultiple']);
            Route::get('/signatures', [SignatureDocumentController::class, 'index']);
            Route::get('/signatures-mobile', [SignatureDocumentController::class, 'indexMobile']);
            Route::get('/signatures/documents/{id}', [SignatureDocumentController::class, 'show']);
            Route::delete('/signatures/{document}', [SignatureDocumentController::class, 'destroy']);

            Route::post('/signatures/{signature_document}/init/{token}', [SignatureController::class, 'init']);
            Route::post('/signatures/{signature_document}/init/signature/{signature}/{token}/{stamp}', [SignatureController::class, 'initWithPosition']);
            Route::post('/signatures/{signature_document}/process/{processId}/sign/{token}', [SignatureController::class, 'sign']);
            Route::post('/signatures/{signature_document}/decline', [SignatureController::class, 'decline']);
            Route::get('/signedPdf/callback', [SignatureDocumentController::class, 'signatureCallback'])->name('signature.callback');

            Route::post('/revocations', [RevocationController::class, 'store']);
            Route::delete('/revocations/{revocation}', [RevocationController::class, 'destroy']);

            // Routes requiring any advanced identity verification
            Route::middleware(['advanced.identity'])->group(function () {
                Route::get('can-buy-vid', \App\Http\Controllers\Singletons\CheckVidEligibilityController::class);
            });

            // Routes requiring specifically in-person advanced verification
            Route::middleware(['inperson.advanced.identity'])->group(function () {
                Route::get('can-buy-token', \App\Http\Controllers\Singletons\CheckTokenEligibilityController::class);
            });

            Route::post('/identity/initiate-in-person', [UserController::class, 'initiateInPersonIdentity']);

            // STRUCTURE EMPLOYEE MANAGEMENT ROUTES
            Route::get('/structures/{structure}/employees', [StructureController::class, 'listEmployees']);
            Route::post('/structures/{structure}/invite-employee', [StructureController::class, 'inviteEmployee']);
            Route::post('/structures/{structure}/add-employee', [StructureController::class, 'addEmployee']);
            Route::put('/structures/{structure}/employees/{user}', [StructureController::class, 'updateEmployeeRole']);
            Route::delete('/structures/{structure}/employees/{user}', [StructureController::class, 'removeEmployee']);

            // EMPLOYEE INVITATION ACCEPTANCE ROUTES
            Route::get('/invitations', [UserController::class, 'listInvitations']);
            Route::post('/invitations/{invitation}/accept', [UserController::class, 'acceptInvitation']);
            Route::post('/invitations/{invitation}/reject', [UserController::class, 'rejectInvitation']);
            Route::post('/employees/create', [UserController::class, 'createEmployee']);

            // STAMPS ROUTES
            Route::post('/stamps', [StampController::class, 'store']);
            Route::get('/stamps', [StampController::class, 'index']);
            Route::get('/stamps/{id}', [StampController::class, 'show']);
            Route::delete('/stamps/{id}', [StampController::class, 'destroy']);
            Route::post('/stamps/{id}', [StampController::class, 'update']);
        });

        Route::middleware('role:client|tech_one|tech_two|tech_three|superviseur')->group(function () {
            Route::post('/management/structure-subscriptions/validate-employee-request', [StructureSubscriptionController::class, 'validateEmployeeRequest']);

            Route::get('signing-identities', [SigningIdentityController::class, 'getSigningIdentities']);
            Route::get('signing-identities/{identityId}', [SigningIdentityController::class, 'getSigningIdentity']);
            Route::put('signing-identities/{identityId}/status', [SigningIdentityController::class, 'updateSigningIdentityStatus']);
            Route::delete('signing-identities/{identityId}', [SigningIdentityController::class, 'deleteSigningIdentity']);
            Route::post('/management/user-subscriptions/update-statuses', [UserSubscriptionController::class, 'updateMultipleStatuses']);

            Route::put('/revocations/status', [RevocationController::class, 'updateMultipleStatus']);
            Route::put('/revocations/{revocation}/status', [RevocationController::class, 'updateStatus']);
        });
    });

    // CLIENTS AUTHENTICATIONS PUBLICS ROUTES
    Route::post('/clients/send-otp', [UserController::class, 'sendOtp'])->middleware(['guest', 'transaction']);
    Route::post('/clients/verify-otp', [UserController::class, 'verifyOtp'])->middleware(['guest']);
    Route::post('/clients/login', [UserController::class, 'login']);
    Route::post('/mobile/login', [UserController::class, 'loginMobile']);
    Route::post('/clients/password/link', [PasswordResetController::class, 'sendResetLink']);
    Route::post('/clients/password/reset', [PasswordResetController::class, 'resetPassword']);
    Route::post('/clients/all/reset', [PasswordResetController::class, 'resetAll']);
    Route::post('/clients/some/reset', [PasswordResetController::class, 'resetSome']);
    Route::post('/clients/init-password-reset', [UserController::class, 'forgotPassword']);

    Route::post('/clients/advanced-id-mid', [UserController::class, 'advancedSubscriptionMid']);

    // PUBLIC FOREIGNER ENROLLMENT ROUTES (no NPI)
    Route::post('/foreigner/send-otp', [ForeignerEnrollmentController::class, 'sendOtp'])->middleware(['guest', 'transaction']);
    Route::post('/foreigner/verify-otp', [ForeignerEnrollmentController::class, 'verifyOtp'])->middleware(['guest']);
    Route::post('/foreigner/enroll', [ForeignerEnrollmentController::class, 'submitEnrollment'])->middleware(['guest']);

    // ADMINS AUTHENTICATIONS PUBLICS ROUTES
    Route::post('/admins/send-otp', [AuthController::class, 'sendOtp'])->middleware('guest');
    Route::post('/admins/verify-otp', [AuthController::class, 'verifyOtp']);
    Route::post('/admins/logout', [AuthController::class, 'logoutAdmin'])->middleware('auth');
    Route::post('/admin/login', [AuthController::class, 'loginAdmin']);
    Route::post('/admin/password/link', [AuthController::class, 'sendPasswordResetLink']);
    Route::post('/admin/password/reset', [AuthController::class, 'resetPassword']);
    Route::post('/signing-identities/provision', [SigningIdentityController::class, 'provisionSignature']);
    Route::get('/decrypt/token/file/{filename}', [EncryptionController::class, 'decryptAndDisplay']);
    Route::get('users/search', [UserController::class, 'search']);
    Route::post('users-email/search', [UserController::class, 'searchPost']);

    // AJOUTEZ ICI LES ROUTES PUBLIQUES D'INVITATION:
    Route::get('/invitations/{token}/details', [UserController::class, 'getInvitationDetails']);
    Route::post('/invitations/{token}/respond', [UserController::class, 'respondToInvitation']);
});
