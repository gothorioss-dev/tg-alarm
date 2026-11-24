<?php

namespace App\Providers;

use App\Monitor\ProcessStatus;
use App\Reconcile\ReconcileStrategy;
use App\Telegram\TelegramClient;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
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
        $this->app->singleton(ProcessStatus::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(function (MessageLogged $event) {
            if (in_array($event->level, ['error', 'critical', 'alert', 'emergency'], true)) {
                $this->app->make(ProcessStatus::class)->error($event->message);
            }
        });
    }
}
