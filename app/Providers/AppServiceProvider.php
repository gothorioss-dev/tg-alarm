<?php

namespace App\Providers;

use App\Reconcile\ReconcileStrategy;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ReconcileStrategy::class, fn ($app) => $app->make(config('sources.strategy')));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
