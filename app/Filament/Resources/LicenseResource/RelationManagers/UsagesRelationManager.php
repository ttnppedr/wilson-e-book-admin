<?php

namespace App\Filament\Resources\LicenseResource\RelationManagers;

use App\Filament\Resources\LicenseUsageResource;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use LucaLongo\LaravelLicensingFilamentManager\Filament\Resources\LicenseResource\RelationManagers\UsagesRelationManager as BaseUsagesRelationManager;

/**
 * MVP 精簡版 UsagesRelationManager。
 * 移除狀態欄位（只有 active）、撤銷/心跳/刪除動作。可編輯名稱。
 */
class UsagesRelationManager extends BaseUsagesRelationManager
{
    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->label(__('laravel-licensing-filament-manager::license-usage.fields.name'))
                    ->maxLength(255)
                    ->helperText('為此裝置命名，方便辨識（例如：王小明的手機）'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('usage_fingerprint')
            ->columns(LicenseUsageResource::usageColumns())
            ->recordActions([
                ViewAction::make()
                    ->label(__('laravel-licensing-filament-manager::common.actions.view')),
                EditAction::make()
                    ->label(__('laravel-licensing-filament-manager::common.actions.edit')),
            ])
            ->defaultSort('registered_at', 'desc');
    }
}
