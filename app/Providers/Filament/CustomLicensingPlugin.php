<?php

namespace App\Providers\Filament;

use App\Filament\Resources\LicenseScopeResource;
use LucaLongo\LaravelLicensingFilamentManager\Filament\Resources\LicenseResource;
use LucaLongo\LaravelLicensingFilamentManager\Filament\Resources\LicenseUsageResource;
use LucaLongo\LaravelLicensingFilamentManager\LaravelLicensingFilamentManagerPlugin;

/**
 * 移除 vendor 的 LicenseScopeResource，改由 App\Filament\Resources\LicenseScopeResource
 * 透過 AdminPanelProvider::discoverResources() 自動載入。
 *
 * @see LicenseScopeResource
 */
class CustomLicensingPlugin extends LaravelLicensingFilamentManagerPlugin
{
    protected function getResources(): array
    {
        return [
            LicenseResource::class,
            LicenseUsageResource::class,
        ];
    }
}
