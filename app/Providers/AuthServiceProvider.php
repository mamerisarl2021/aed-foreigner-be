<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use App\Models\{
    Attachment, Cases, Identity, IdRequest, Message, OTP, Structure, StructurePackage, StructureSubscription, UserPackage, UserSubscription
};
use App\Policies\AuthorizationPolicy;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Attachment::class => AuthorizationPolicy::class,
        Cases::class => AuthorizationPolicy::class,
        Identity::class => AuthorizationPolicy::class,
        IdRequest::class => AuthorizationPolicy::class,
        Message::class => AuthorizationPolicy::class,
        OTP::class => AuthorizationPolicy::class,
        Structure::class => AuthorizationPolicy::class,
        StructurePackage::class => AuthorizationPolicy::class,
        StructureSubscription::class => AuthorizationPolicy::class,
        UserPackage::class => AuthorizationPolicy::class,
        UserSubscription::class => AuthorizationPolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        $this->registerPolicies();

        ResetPassword::createUrlUsing(function (object $notifiable, string $token) {
            return config('app.frontend_url')."/password-reset/$token?email={$notifiable->getEmailForPasswordReset()}";
        });
    }
}
