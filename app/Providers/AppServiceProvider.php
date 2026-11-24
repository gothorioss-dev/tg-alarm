<?php

namespace App\Providers;

use App\Reconcile\ReconcileStrategy;
use App\Telegram\TelegramClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ReconcileStrategy::class, fn ($app) => $app->make(config('sources.strategy')));
        // One open connection per process.
        $this->app->singleton(TelegramClient::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
