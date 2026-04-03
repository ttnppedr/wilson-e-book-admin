<?php

namespace App\Filament\Resources\LicenseScopeResource\Pages;

use App\Filament\Resources\LicenseScopeResource;
use LucaLongo\LaravelLicensingFilamentManager\Filament\Resources\LicenseScopeResource\Pages\EditLicenseScope as BaseEditLicenseScope;

class EditLicenseScope extends BaseEditLicenseScope
{
    protected static string $resource = LicenseScopeResource::class;
}
