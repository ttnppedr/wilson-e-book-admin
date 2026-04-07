<?php

namespace App\Filament\Resources\LicenseResource\RelationManagers;

use App\Filament\Resources\LicenseUsageResource;
use Filament\Actions\ViewAction;
use Filament\Tables\Table;
use LucaLongo\LaravelLicensingFilamentManager\Filament\Resources\LicenseResource\RelationManagers\UsagesRelationManager as BaseUsagesRelationManager;

/**
 * MVP 精簡版 UsagesRelationManager。
 * 唯讀顯示，不可編輯。
 */
class UsagesRelationManager extends BaseUsagesRelationManager
{
    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('usage_fingerprint')
            ->columns(LicenseUsageResource::usageColumns())
            ->recordActions([
                ViewAction::make()
                    ->label(__('laravel-licensing-filament-manager::common.actions.view')),
            ])
            ->defaultSort('registered_at', 'desc');
    }
}
