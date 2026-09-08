<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Middleware\EnsurePsceqApiKey;
use App\Models\ActivityLog;
use App\Models\EnrollmentRejectMotif;
use App\Models\EnrollmentRequest;
use App\Models\Identity;
use App\Models\OTP;
use App\Models\PsceqClient;
use App\Models\User;
use App\Policies\ActivityLogPolicy;
use App\Policies\AuthorizationPolicy;
use App\Policies\EnrolledCompanyPolicy;
use App\Policies\EnrolledPersonPolicy;
use App\Policies\EnrollmentRejectMotifPolicy;
use App\Policies\EnrollmentRequestPolicy;
use App\Policies\PlatformPolicy;
use App\Policies\PsceqClientPolicy;
use App\Policies\UserPolicy;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;
use Opcodes\LogViewer\Facades\LogViewer;

class AuthServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    protected $policies = [
        EnrollmentRequest::class => EnrollmentRequestPolicy::class,
        EnrollmentRejectMotif::class => EnrollmentRejectMotifPolicy::class,
        ActivityLog::class => ActivityLogPolicy::class,
        Identity::class => AuthorizationPolicy::class,
        OTP::class => AuthorizationPolicy::class,
        User::class => UserPolicy::class,
        PsceqClient::class => PsceqClientPolicy::class,
    ];

    public function boot(): void
    {
        $this->registerPolicies();

        Gate::define('viewAnyEnrolledPerson', [EnrolledPersonPolicy::class, 'viewAny']);
        Gate::define('viewEnrolledPerson', [EnrolledPersonPolicy::class, 'view']);
        Gate::define('viewAnyEnrolledCompany', [EnrolledCompanyPolicy::class, 'viewAny']);
        Gate::define('viewEnrolledCompany', [EnrolledCompanyPolicy::class, 'view']);
        Gate::define('updateEnrolledCompanyStatus', [EnrolledCompanyPolicy::class, 'updateStatus']);
        Gate::define('queryAsPsceq', function (?User $user): bool {
            return is_string(request()->attributes->get(EnsurePsceqApiKey::CLIENT_ID_ATTRIBUTE));
        });
        Gate::define('viewStats', [PlatformPolicy::class, 'viewStats']);
        Gate::define('viewAudits', [PlatformPolicy::class, 'viewAudits']);
        Gate::define('viewEncryptedDocuments', [PlatformPolicy::class, 'viewEncryptedDocuments']);
        Gate::define('viewApiDocs', [PlatformPolicy::class, 'viewApiDocs']);

        LogViewer::auth(fn ($request) => $request->user()?->hasRole(config('roles.administrateur_plateforme')) ?? false);

        ResetPassword::createUrlUsing(function (CanResetPassword $notifiable, string $token) {
            return config('app.frontend_url')."/password-reset/$token?email={$notifiable->getEmailForPasswordReset()}";
        });
    }
}
