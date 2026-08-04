<?php

namespace App\Providers;

use App\Domain\Aircraft\Models\Aircraft;
use App\Domain\Aircraft\Policies\AircraftPolicy;
use App\Domain\Customers\Models\Customer;
use App\Domain\Customers\Models\CustomerContact;
use App\Domain\Customers\Policies\CustomerContactPolicy;
use App\Domain\Customers\Policies\CustomerPolicy;
use App\Domain\FlightRequests\Models\FlightRequest;
use App\Domain\FlightRequests\Policies\FlightRequestPolicy;
use App\Domain\Services\Models\Service;
use App\Domain\Services\Policies\ServicePolicy;
use App\Domain\Suppliers\Models\Supplier;
use App\Domain\Suppliers\Models\SupplierContact;
use App\Domain\Suppliers\Policies\SupplierContactPolicy;
use App\Domain\Suppliers\Policies\SupplierPolicy;
use App\Domain\Tenancy\Policies\UserPolicy;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CurrentCompany::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::before(fn (User $user) => $user->hasRole('Admin') ? true : null);

        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Customer::class, CustomerPolicy::class);
        Gate::policy(CustomerContact::class, CustomerContactPolicy::class);
        Gate::policy(Aircraft::class, AircraftPolicy::class);
        Gate::policy(Supplier::class, SupplierPolicy::class);
        Gate::policy(SupplierContact::class, SupplierContactPolicy::class);
        Gate::policy(FlightRequest::class, FlightRequestPolicy::class);
        Gate::policy(Service::class, ServicePolicy::class);

        // Defends against session fixation: without this, a session cookie
        // planted before login (with 2fa_passed already true) would let an
        // attacker skip the 2FA challenge for whoever logs in on it.
        Event::listen(Login::class, fn () => session()->forget('2fa_passed'));
    }
}
