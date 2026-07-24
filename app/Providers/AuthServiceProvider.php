<?php

namespace App\Providers;

use App\Models\ActivityLog;
use App\Models\EnrollmentRejectMotif;
use App\Models\EnrollmentRequest;
use App\Models\Identity;
use App\Models\OTP;
use App\Policies\ActivityLogPolicy;
use App\Policies\AuthorizationPolicy;
use App\Policies\EnrolledPersonPolicy;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    protected $policies = [
        EnrollmentRequest::class => \App\Policies\EnrollmentRequestPolicy::class,
        EnrollmentRejectMotif::class => \App\Policies\EnrollmentRejectMotifPolicy::class,
        ActivityLog::class => ActivityLogPolicy::class,
        Identity::class => AuthorizationPolicy::class,
        OTP::class => AuthorizationPolicy::class,
    ];

    public function boot(): void
    {
        $this->registerPolicies();

        $enrolledPersonPolicy = new EnrolledPersonPolicy;
        Gate::define('viewAnyEnrolledPerson', fn ($user) => $enrolledPersonPolicy->viewAny($user));
        Gate::define('viewEnrolledPerson', fn ($user) => $enrolledPersonPolicy->view($user));

        ResetPassword::createUrlUsing(function (object $notifiable, string $token) {
            return config('app.frontend_url')."/password-reset/$token?email={$notifiable->getEmailForPasswordReset()}";
        });
    }
}
