<?php

namespace App\Filament\Resources\LicenseResource\Pages;

use App\Filament\Resources\LicenseResource;
use LucaLongo\LaravelLicensingFilamentManager\Filament\Resources\LicenseResource\Pages\CreateLicense as BaseCreateLicense;

class CreateLicense extends BaseCreateLicense
{
    protected static string $resource = LicenseResource::class;
}
