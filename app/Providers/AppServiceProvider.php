<?php

namespace App\Providers;

use App\Application\Contracts\Repositories\CustomerRepository;
use App\Application\Contracts\Repositories\PurchaseOrderRepository;
use App\Application\Contracts\Repositories\ProductRepository;
use App\Application\Contracts\Repositories\StockInRepository;
use App\Application\Contracts\Repositories\SupplierRepository;
use App\Domain\IdentityAccess\Enums\UserRole;
use App\Models\Customer;
use App\Models\PurchaseOrder;
use App\Models\Product;
use App\Models\StockIn;
use App\Models\Supplier;
use App\Models\User;
use App\Policies\CustomerPolicy;
use App\Policies\PurchaseOrderPolicy;
use App\Policies\ProductPolicy;
use App\Policies\StockInPolicy;
use App\Policies\SupplierPolicy;
use App\Policies\UserPolicy;
use App\Infrastructure\Persistence\Eloquent\Repositories\EloquentCustomerRepository;
use App\Infrastructure\Persistence\Eloquent\Repositories\EloquentPurchaseOrderRepository;
use App\Infrastructure\Persistence\Eloquent\Repositories\EloquentProductRepository;
use App\Infrastructure\Persistence\Eloquent\Repositories\EloquentStockInRepository;
use App\Infrastructure\Persistence\Eloquent\Repositories\EloquentSupplierRepository;
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
        $this->app->bind(ProductRepository::class, EloquentProductRepository::class);
        $this->app->bind(SupplierRepository::class, EloquentSupplierRepository::class);
        $this->app->bind(CustomerRepository::class, EloquentCustomerRepository::class);
        $this->app->bind(PurchaseOrderRepository::class, EloquentPurchaseOrderRepository::class);
        $this->app->bind(StockInRepository::class, EloquentStockInRepository::class);
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
        Gate::policy(Product::class, ProductPolicy::class);
        Gate::policy(Supplier::class, SupplierPolicy::class);
        Gate::policy(Customer::class, CustomerPolicy::class);
        Gate::policy(PurchaseOrder::class, PurchaseOrderPolicy::class);
        Gate::policy(StockIn::class, StockInPolicy::class);
    }
}
