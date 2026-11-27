<?php

namespace App\Filament\Resources\LicenseUsageResource\Pages;

use App\Filament\Resources\LicenseUsageResource;
use Filament\Actions\Action;
use LucaLongo\LaravelLicensingFilamentManager\Filament\Resources\LicenseUsageResource\Pages\ViewLicenseUsage as BaseViewLicenseUsage;

class ViewLicenseUsage extends BaseViewLicenseUsage
{
    protected static string $resource = LicenseUsageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('diagnostics')
                ->label('此裝置的診斷紀錄')
                ->icon('heroicon-o-bug-ant')
                ->color('gray')
                ->url(fn (): string => LicenseUsageResource::diagnosticsUrl($this->getRecord())),
        ];
    }
}
