<?php

namespace App\Filament\Resources\LicenseUsageResource\Pages;

use App\Filament\Resources\LicenseUsageResource;
use LucaLongo\LaravelLicensingFilamentManager\Filament\Resources\LicenseUsageResource\Pages\ListLicenseUsages as BaseListLicenseUsages;

class ListLicenseUsages extends BaseListLicenseUsages
{
    protected static string $resource = LicenseUsageResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
