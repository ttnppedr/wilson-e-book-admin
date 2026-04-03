<?php

namespace App\Providers\Filament;

use LucaLongo\LaravelLicensingFilamentManager\LaravelLicensingFilamentManagerPlugin;

/**
 * 覆寫 vendor plugin，不註冊任何 vendor resource。
 * 所有資源由 App\Filament\Resources 下的自訂版本透過 discoverResources() 自動載入。
 */
class CustomLicensingPlugin extends LaravelLicensingFilamentManagerPlugin
{
    protected function getResources(): array
    {
        return [];
    }
}
