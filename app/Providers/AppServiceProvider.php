<?php

namespace App\Providers;

use App\Domain\IdentityAccess\Enums\UserRole;
use App\Models\User;
use App\Policies\UserPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('login', function (Request $request): Limit {
            return Limit::perMinute(5)->by($request->ip().'|'.strtolower((string) $request->input('email')));
        });

        RateLimiter::for('api', function (Request $request): Limit {
            $identifier = $request->user()?->id ? 'user:'.$request->user()->id : 'ip:'.$request->ip();

            return Limit::perMinute(120)->by($identifier);
        });

        Gate::define('access-admin', fn (User $user): bool => $user->role === UserRole::Admin && $user->isActive());
        Gate::define('access-staff', fn (User $user): bool => in_array($user->role, [UserRole::Admin, UserRole::Staff], true) && $user->isActive());
        Gate::policy(User::class, UserPolicy::class);
    }
}
