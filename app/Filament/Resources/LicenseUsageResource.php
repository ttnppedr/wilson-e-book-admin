<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LicenseUsageResource\Pages;
use LucaLongo\LaravelLicensingFilamentManager\Filament\Resources\LicenseUsageResource as BaseLicenseUsageResource;

/**
 * 覆寫 vendor LicenseUsageResource 以隱藏新增按鈕。
 * License Usage 由用戶端 App 透過 API 自動建立，不應在後台手動新增。
 */
class LicenseUsageResource extends BaseLicenseUsageResource
{
    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLicenseUsages::route('/'),
            'view' => Pages\ViewLicenseUsage::route('/{record}'),
            'edit' => Pages\EditLicenseUsage::route('/{record}/edit'),
        ];
    }
}
