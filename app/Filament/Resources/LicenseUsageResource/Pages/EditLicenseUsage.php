<?php

namespace App\Filament\Resources\LicenseUsageResource\Pages;

use App\Filament\Resources\LicenseUsageResource;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditLicenseUsage extends EditRecord
{
    protected static string $resource = LicenseUsageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }
}
