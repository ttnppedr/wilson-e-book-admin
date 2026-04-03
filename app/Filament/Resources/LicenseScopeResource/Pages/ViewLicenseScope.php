<?php

namespace App\Filament\Resources\LicenseScopeResource\Pages;

use App\Filament\Resources\LicenseScopeResource;
use LucaLongo\LaravelLicensingFilamentManager\Filament\Resources\LicenseScopeResource\Pages\ViewLicenseScope as BaseViewLicenseScope;

class ViewLicenseScope extends BaseViewLicenseScope
{
    protected static string $resource = LicenseScopeResource::class;
}
