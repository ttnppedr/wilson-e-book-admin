<?php

namespace App\Filament\Resources\LicenseScopeResource\Pages;

use App\Filament\Resources\LicenseScopeResource;
use LucaLongo\LaravelLicensingFilamentManager\Filament\Resources\LicenseScopeResource\Pages\ListLicenseScopes as BaseListLicenseScopes;

class ListLicenseScopes extends BaseListLicenseScopes
{
    protected static string $resource = LicenseScopeResource::class;
}
