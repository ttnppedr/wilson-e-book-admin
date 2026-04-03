<?php

namespace App\Filament\Resources\LicenseResource\RelationManagers;

use Filament\Forms;
use Filament\Schemas\Schema;
use LucaLongo\LaravelLicensingFilamentManager\Filament\Resources\LicenseResource\RelationManagers\UsagesRelationManager as BaseUsagesRelationManager;
use LucaLongo\Licensing\Enums\UsageStatus;

/**
 * 修正 vendor UsagesRelationManager 的 Forms\Get 型別錯誤（Filament 4 已改為 Schemas\Components\Utilities\Get）。
 */
class UsagesRelationManager extends BaseUsagesRelationManager
{
    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Forms\Components\TextInput::make('usage_fingerprint')
                    ->label(__('laravel-licensing-filament-manager::license-usage.fields.usage_fingerprint'))
                    ->required()
                    ->maxLength(255),

                Forms\Components\Select::make('status')
                    ->label(__('laravel-licensing-filament-manager::license-usage.fields.status'))
                    ->options(UsageStatus::class)
                    ->required(),

                Forms\Components\TextInput::make('name')
                    ->label(__('laravel-licensing-filament-manager::license-usage.fields.name'))
                    ->maxLength(255),

                Forms\Components\TextInput::make('ip')
                    ->label(__('laravel-licensing-filament-manager::license-usage.fields.ip'))
                    ->maxLength(45),

                Forms\Components\TextInput::make('user_agent')
                    ->label(__('laravel-licensing-filament-manager::license-usage.fields.user_agent'))
                    ->maxLength(500),

                Forms\Components\DateTimePicker::make('registered_at')
                    ->label(__('laravel-licensing-filament-manager::license-usage.fields.registered_at'))
                    ->displayFormat('d/m/Y H:i')
                    ->required(),

                Forms\Components\DateTimePicker::make('last_seen_at')
                    ->label(__('laravel-licensing-filament-manager::license-usage.fields.last_seen_at'))
                    ->displayFormat('d/m/Y H:i'),

                Forms\Components\DateTimePicker::make('revoked_at')
                    ->label(__('laravel-licensing-filament-manager::license-usage.fields.revoked_at'))
                    ->displayFormat('d/m/Y H:i')
                    ->visible(fn (callable $get) => $get('status') === UsageStatus::Revoked->value),
            ]);
    }
}
