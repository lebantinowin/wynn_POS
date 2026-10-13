<?php

namespace App\Providers;

use App\Support\OfflineCheckout;
use App\Support\PosStore;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(PosStore::class, fn () => new PosStore);
        $this->app->scoped(OfflineCheckout::class, fn () => new OfflineCheckout);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
