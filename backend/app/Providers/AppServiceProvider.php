<?php

namespace App\Providers;

use App\Contracts\BusinessRegistryVerificationService;
use App\Contracts\DocumentScanner;
use App\Domain\Payments\Contracts\ConnectedAccountProvider;
use App\Domain\Payments\Contracts\PaymentProvider;
use App\Domain\Payments\Contracts\RefundProvider;
use App\Domain\Payments\Providers\Stripe\StripeConnectedAccountProvider;
use App\Domain\Payments\Providers\Stripe\StripePaymentProvider;
use App\Domain\Payments\Providers\Stripe\StripeRefundProvider;
use App\Models\User;
use App\Policies\RestaurantPolicy;
use App\Services\Partner\LocalAbnVerificationService;
use App\Services\Partner\NullDocumentScanner;
use Illuminate\Support\Facades\Gate;
use App\Events\Order\OrderAccepted;
use App\Events\Order\OrderCancelled;
use App\Events\Order\OrderCompleted;
use App\Events\Order\OrderDomainEvent;
use App\Events\Order\OrderExpired;
use App\Events\Order\OrderPlaced;
use App\Events\Order\OrderPreparing;
use App\Events\Order\OrderReady;
use App\Events\Order\OrderRejected;
use App\Listeners\Order\SendOrderDomainNotifications;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(BusinessRegistryVerificationService::class, LocalAbnVerificationService::class);
        $this->app->bind(DocumentScanner::class, NullDocumentScanner::class);
        $this->app->bind(PaymentProvider::class, StripePaymentProvider::class);
        $this->app->bind(ConnectedAccountProvider::class, StripeConnectedAccountProvider::class);
        $this->app->bind(RefundProvider::class, StripeRefundProvider::class);
    }

    public function boot(): void
    {
        Gate::define('permission', function (User $user, string $permission): bool {
            return $user->hasPermission($permission);
        });

        Gate::define('role', function (User $user, string|array $roles): bool {
            $list = is_array($roles) ? $roles : [$roles];

            return $user->hasAnyRole($list);
        });

        Gate::define('access-restaurant', [RestaurantPolicy::class, 'access']);
        Gate::define('access-admin', function (User $user): bool {
            return $user->isSuperAdmin() && $user->hasPermission('view_super_admin_dashboard');
        });

        $notify = SendOrderDomainNotifications::class;
        Event::listen(OrderPlaced::class, [$notify, 'handlePlaced']);
        foreach ([OrderAccepted::class, OrderRejected::class, OrderPreparing::class, OrderReady::class, OrderCompleted::class, OrderCancelled::class, OrderExpired::class] as $eventClass) {
            Event::listen($eventClass, [$notify, 'handleStatus']);
        }
    }
}
