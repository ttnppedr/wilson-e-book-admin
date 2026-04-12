<?php

namespace App\Providers;

use App\Models\License;
use App\Observers\LicenseObserver;
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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        License::observe(LicenseObserver::class);

        /*
         * Licensing API rate limiters。
         *
         * Key 組成:`sha1(IP | fingerprint)`。複合鍵讓「同 IP 不同 device」與
         * 「同 device 不同 IP」各自獨立計數,攻擊者要同時輪替兩個維度才能繞過,
         * 同時避免把原始 IP/fingerprint 直接存進 cache key。
         *
         * Limit 值來自 `config/licensing.php` 的 `rate_limit.*`,方便環境間調整。
         */
        RateLimiter::for('licensing-activate', fn (Request $request) => Limit::perMinute(
            (int) config('licensing.rate_limit.activate_per_minute', 10)
        )->by($this->licensingRateLimitKey($request)));

        RateLimiter::for('licensing-validate', fn (Request $request) => Limit::perMinute(
            (int) config('licensing.rate_limit.validate_per_minute', 60)
        )->by($this->licensingRateLimitKey($request)));

        RateLimiter::for('api-wordwall', fn (Request $request) => Limit::perMinute(60)
            ->by(sha1((string) $request->ip())));
    }

    /**
     * 為 licensing API rate limiter 組出 IP + fingerprint 複合鍵。
     */
    private function licensingRateLimitKey(Request $request): string
    {
        return sha1($request->ip().'|'.(string) $request->input('fingerprint', ''));
    }
}
