<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LicenseUsageResource\Pages;
use Filament\Forms;
use Filament\Schemas\Schema;
use LucaLongo\LaravelLicensingFilamentManager\Filament\Resources\LicenseUsageResource as BaseLicenseUsageResource;
use LucaLongo\Licensing\Enums\UsageStatus;

/**
 * MVP 精簡版 LicenseUsageResource。
 * 隱藏新增按鈕（Usage 由 API 自動建立）+ 隱藏 client_type/meta 欄位。
 */
class LicenseUsageResource extends BaseLicenseUsageResource
{
    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Forms\Components\Select::make('license_id')
                    ->relationship('license', 'uid')
                    ->label(__('laravel-licensing-filament-manager::licensing.fields.license_key'))
                    ->required()
                    ->searchable()
                    ->preload(),

                Forms\Components\TextInput::make('usage_fingerprint')
                    ->label(__('laravel-licensing-filament-manager::license-usage.fields.usage_fingerprint'))
                    ->required()
                    ->maxLength(255),

                Forms\Components\Select::make('status')
                    ->label(__('laravel-licensing-filament-manager::license-usage.fields.status'))
                    ->options(UsageStatus::class)
                    ->default(UsageStatus::Active->value)
                    ->required(),

                Forms\Components\TextInput::make('name')
                    ->label(__('laravel-licensing-filament-manager::license-usage.fields.name'))
                    ->maxLength(255),

                Forms\Components\TextInput::make('ip')
                    ->label(__('laravel-licensing-filament-manager::license-usage.fields.ip'))
                    ->maxLength(45),

                Forms\Components\Textarea::make('user_agent')
                    ->label(__('laravel-licensing-filament-manager::license-usage.fields.user_agent'))
                    ->rows(2)
                    ->columnSpanFull(),

                Forms\Components\DateTimePicker::make('registered_at')
                    ->label(__('laravel-licensing-filament-manager::license-usage.fields.registered_at'))
                    ->default(now())
                    ->required(),

                Forms\Components\DateTimePicker::make('last_seen_at')
                    ->label(__('laravel-licensing-filament-manager::license-usage.fields.last_seen_at'))
                    ->default(now())
                    ->nullable(),

                Forms\Components\DateTimePicker::make('revoked_at')
                    ->label(__('laravel-licensing-filament-manager::license-usage.fields.revoked_at'))
                    ->nullable()
                    ->visible(fn (callable $get) => $get('status') === UsageStatus::Revoked->value),
            ]);
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
