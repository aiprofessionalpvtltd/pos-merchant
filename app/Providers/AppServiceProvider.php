<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(\App\Services\Sms\SmsSender::class, \App\Services\Sms\LogSmsSender::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Separate counters per group; the throttle:N,1 shorthand shares one counter per IP.
        foreach (['v1-lookup' => 30, 'v1-credentials' => 20, 'v1-otp' => 10, 'v1-registration' => 60, 'v1-merchant-create' => 20, 'v1-subscription' => 30, 'v1-staff' => 60, 'v1-merchant' => 60, 'v1-payments' => 120, 'v1-inventory' => 240, 'v1-cart' => 300, 'v1-orders' => 240] as $name => $perMinute) {
            RateLimiter::for($name, fn (Request $request) => Limit::perMinute($perMinute)->by($name.'|'.$request->ip()));
        }
    }
}
