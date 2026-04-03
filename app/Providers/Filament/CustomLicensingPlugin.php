<?php

namespace App\Providers\Filament;

use App\Filament\Resources\LicenseScopeResource;
use App\Filament\Resources\LicenseUsageResource;
use LucaLongo\LaravelLicensingFilamentManager\Filament\Resources\LicenseResource;
use LucaLongo\LaravelLicensingFilamentManager\LaravelLicensingFilamentManagerPlugin;

/**
 * 移除 vendor 的 LicenseScopeResource 與 LicenseUsageResource，
 * 改由 App\Filament\Resources 下的自訂版本透過 discoverResources() 自動載入。
 *
 * @see LicenseScopeResource
 * @see LicenseUsageResource
 */
class CustomLicensingPlugin extends LaravelLicensingFilamentManagerPlugin
{
    protected function getResources(): array
    {
        return [
            LicenseResource::class,
        ];
    }
}
