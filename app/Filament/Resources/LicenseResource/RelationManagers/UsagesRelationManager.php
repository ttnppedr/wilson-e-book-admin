<?php

namespace App\Filament\Resources\LicenseResource\RelationManagers;

use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms;
use Filament\Schemas\Schema;
use Filament\Tables;
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
            ->columns([
                Tables\Columns\TextColumn::make('usage_fingerprint')
                    ->label(__('laravel-licensing-filament-manager::license-usage.fields.usage_fingerprint'))
                    ->searchable()
                    ->copyable()
                    ->limit(20)
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('name')
                    ->label(__('laravel-licensing-filament-manager::license-usage.fields.name'))
                    ->searchable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('ip')
                    ->label(__('laravel-licensing-filament-manager::license-usage.fields.ip'))
                    ->searchable(),

                Tables\Columns\TextColumn::make('registered_at')
                    ->label(__('laravel-licensing-filament-manager::license-usage.fields.registered_at'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('last_seen_at')
                    ->label(__('laravel-licensing-filament-manager::license-usage.fields.last_seen_at'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->recordActions([
                ViewAction::make()
                    ->label(__('laravel-licensing-filament-manager::common.actions.view')),
                EditAction::make()
                    ->label(__('laravel-licensing-filament-manager::common.actions.edit')),
            ])
            ->defaultSort('registered_at', 'desc');
    }
}
