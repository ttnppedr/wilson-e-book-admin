<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LicenseScopeResource\Pages;
use App\Filament\Resources\LicenseScopeResource\RelationManagers\TemplatesRelationManager;
use LucaLongo\LaravelLicensingFilamentManager\Filament\Resources\LicenseScopeResource as BaseLicenseScopeResource;
use LucaLongo\LaravelLicensingFilamentManager\Filament\Resources\LicenseScopeResource\RelationManagers\LicensesRelationManager;
use LucaLongo\LaravelLicensingFilamentManager\Filament\Resources\LicenseScopeResource\RelationManagers\SigningKeysRelationManager;

/**
 * 覆寫 vendor LicenseScopeResource 以注入修正版 TemplatesRelationManager（補上缺少的 getTitle）。
 * 待 masterix21/laravel-licensing-filament-manager 修復後，本類別及相關 Pages、CustomLicensingPlugin 可移除。
 */
class LicenseScopeResource extends BaseLicenseScopeResource
{
    public static function getRelations(): array
    {
        return [
            TemplatesRelationManager::class,
            SigningKeysRelationManager::class,
            LicensesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLicenseScopes::route('/'),
            'create' => Pages\CreateLicenseScope::route('/create'),
            'view' => Pages\ViewLicenseScope::route('/{record}'),
            'edit' => Pages\EditLicenseScope::route('/{record}/edit'),
        ];
    }
}
