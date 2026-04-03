<?php

namespace App\Filament\Resources\LicenseUsageResource\Pages;

use App\Filament\Resources\LicenseUsageResource;
use LucaLongo\LaravelLicensingFilamentManager\Filament\Resources\LicenseUsageResource\Pages\ViewLicenseUsage as BaseViewLicenseUsage;

class ViewLicenseUsage extends BaseViewLicenseUsage
{
    protected static string $resource = LicenseUsageResource::class;
}
