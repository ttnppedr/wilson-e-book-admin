<?php

namespace App\Filament\Resources\LicenseScopeResource\Pages;

use App\Filament\Resources\LicenseScopeResource;
use LucaLongo\LaravelLicensingFilamentManager\Filament\Resources\LicenseScopeResource\Pages\CreateLicenseScope as BaseCreateLicenseScope;

class CreateLicenseScope extends BaseCreateLicenseScope
{
    protected static string $resource = LicenseScopeResource::class;
}
