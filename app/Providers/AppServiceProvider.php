<?php

namespace App\Providers;

use App\Observers\LicenseObserver;
use Filament\Forms\Components\Select;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use LucaLongo\Licensing\Enums\KeyStatus;
use LucaLongo\Licensing\Enums\LicenseStatus;
use LucaLongo\Licensing\Enums\UsageStatus;
use LucaLongo\Licensing\Models\License;

class AppServiceProvider extends ServiceProvider
{
    /**
     * @var array<string, string>
     */
    private const ENUM_LABELS = [
        // LicenseStatus
        'pending' => '待啟用',
        'active' => '啟用中',
        'grace' => '寬限期',
        'expired' => '已到期',
        'suspended' => '已暫停',
        'cancelled' => '已取消',
        // UsageStatus
        'revoked' => '已撤銷',
    ];

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
        $this->configureEnumLabels();

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));
    }

    private function configureEnumLabels(): void
    {
        TextColumn::configureUsing(function (TextColumn $column): void {
            $column->formatStateUsing(function (mixed $state) {
                if ($state instanceof LicenseStatus || $state instanceof UsageStatus || $state instanceof KeyStatus) {
                    return self::ENUM_LABELS[$state->value] ?? $state->name;
                }

                return $state;
            });
        });

        Select::configureUsing(function (Select $select): void {
            $originalOptions = null;

            $select->afterStateHydrated(function (Select $component) use (&$originalOptions): void {
                $options = $component->getOptions();
                $translated = false;

                foreach ($options as $key => $label) {
                    if (isset(self::ENUM_LABELS[strtolower($label)])) {
                        $options[$key] = self::ENUM_LABELS[strtolower($label)];
                        $translated = true;
                    }
                }

                if ($translated) {
                    $component->options($options);
                }
            });
        });
    }
}
