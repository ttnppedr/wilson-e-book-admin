<?php

namespace App\Filament\Resources\LicenseUsageResource\Pages;

use App\Filament\Resources\LicenseUsageResource;
use LucaLongo\LaravelLicensingFilamentManager\Filament\Resources\LicenseUsageResource\Pages\EditLicenseUsage as BaseEditLicenseUsage;

class EditLicenseUsage extends BaseEditLicenseUsage
{
    protected static string $resource = LicenseUsageResource::class;
}
