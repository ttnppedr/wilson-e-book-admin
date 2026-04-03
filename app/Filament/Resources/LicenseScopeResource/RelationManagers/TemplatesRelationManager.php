<?php

namespace App\Filament\Resources\LicenseScopeResource\RelationManagers;

use Illuminate\Database\Eloquent\Model;
use LucaLongo\LaravelLicensingFilamentManager\Filament\Resources\LicenseScopeResource\RelationManagers\TemplatesRelationManager as BaseTemplatesRelationManager;

/**
 * 覆寫 vendor TemplatesRelationManager 補上缺少的 getTitle()，
 * 使 relation manager tab 標題可正確翻譯（與 LicensesRelationManager、SigningKeysRelationManager 一致）。
 * 待 masterix21/laravel-licensing-filament-manager 修復後可移除。
 */
class TemplatesRelationManager extends BaseTemplatesRelationManager
{
    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('laravel-licensing-filament-manager::license-scope.relations.templates');
    }
}
