<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LicenseUsageResource\Pages;
use App\Services\LicenseKeyGenerator;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LucaLongo\LaravelLicensingFilamentManager\Filament\Resources\LicenseUsageResource as BaseLicenseUsageResource;
use App\Models\LicenseUsage;

/**
 * MVP 精簡版 LicenseUsageResource。
 * 隱藏新增按鈕、狀態欄位。只能編輯名稱，其餘唯讀。
 * 授權金鑰以大寫顯示，每 5 字元加 dash。
 */
class LicenseUsageResource extends BaseLicenseUsageResource
{
    public static function getRecordTitle(?Model $record): string|\Illuminate\Contracts\Support\Htmlable|null
    {
        return $record?->license?->name;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('license');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                TextEntry::make('license.name')
                    ->label('授權名稱'),

                TextEntry::make('license_key_display')
                    ->label(__('laravel-licensing-filament-manager::licensing.fields.license_key'))
                    ->state(function (LicenseUsage $record) {
                        $key = $record->license?->retrieveKey();
                        if (! $key) {
                            return $record->license?->uid ?? '—';
                        }

                        return LicenseKeyGenerator::format($key);
                    })
                    ->copyable(),

                TextEntry::make('usage_fingerprint')
                    ->label(__('laravel-licensing-filament-manager::license-usage.fields.usage_fingerprint'))
                    ->copyable(),

                TextEntry::make('ip')
                    ->label(__('laravel-licensing-filament-manager::license-usage.fields.ip')),

                TextEntry::make('user_agent')
                    ->label(__('laravel-licensing-filament-manager::license-usage.fields.user_agent')),

                TextEntry::make('registered_at')
                    ->label(__('laravel-licensing-filament-manager::license-usage.fields.registered_at'))
                    ->dateTime('Y/m/d H:i:s'),

                TextEntry::make('last_seen_at')
                    ->label(__('laravel-licensing-filament-manager::license-usage.fields.last_seen_at'))
                    ->dateTime('Y/m/d H:i:s'),
            ]);
    }

    /**
     * 共用的使用紀錄表格欄位，供 LicenseUsageResource 和 UsagesRelationManager 共用。
     *
     * @return array<Tables\Columns\TextColumn>
     */
    public static function usageColumns(): array
    {
        return [
            Tables\Columns\TextColumn::make('usage_fingerprint')
                ->label(__('laravel-licensing-filament-manager::license-usage.fields.usage_fingerprint'))
                ->copyable()
                ->limit(24)
                ->searchable(),

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
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('license.name')
                    ->label('授權名稱')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('license.uid')
                    ->label('授權碼')
                    ->formatStateUsing(function ($state, LicenseUsage $record) {
                        $key = $record->license?->retrieveKey();
                        if (! $key) {
                            return $state ?? '—';
                        }

                        return LicenseKeyGenerator::format($key);
                    })
                    ->copyable()
                    ->searchable()
                    ->sortable(),
                ...static::usageColumns(),
            ])
            ->recordActions([
                ViewAction::make()
                    ->label(__('laravel-licensing-filament-manager::common.actions.view')),
                EditAction::make()
                    ->label(__('laravel-licensing-filament-manager::common.actions.edit')),
            ])
            ->defaultSort('registered_at', 'desc');
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
