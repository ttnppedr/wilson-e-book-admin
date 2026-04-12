<?php

namespace App\Filament\Widgets;

use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use LucaLongo\Licensing\Enums\UsageStatus;

class RecentLicenseActivations extends TableWidget
{
    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        $licenseUsageModel = config('licensing.models.license_usage');

        return $table
            ->heading(__('laravel-licensing-filament-manager::licensing.widgets.recent_usages.heading'))
            ->query(
                $licenseUsageModel::query()
                    ->with(['license', 'license.scope'])
                    ->latest('registered_at')
                    ->limit(10)
            )
            ->columns([
                Tables\Columns\TextColumn::make('license.name')
                    ->label(__('laravel-licensing-filament-manager::licensing.fields.license_key')),
                Tables\Columns\TextColumn::make('license.scope.name')
                    ->label(__('laravel-licensing-filament-manager::license.fields.license_scope'))
                    ->badge()
                    ->color('info'),
                Tables\Columns\TextColumn::make('usage_fingerprint')
                    ->label(__('laravel-licensing-filament-manager::license-usage.fields.usage_fingerprint'))
                    ->copyable()
                    ->limit(20),
                Tables\Columns\TextColumn::make('user_agent')
                    ->label(__('laravel-licensing-filament-manager::license-usage.fields.user_agent'))
                    ->limit(30)
                    ->tooltip(fn ($record) => $record->user_agent),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('laravel-licensing-filament-manager::license-usage.fields.status'))
                    ->badge()
                    ->colors([
                        'success' => UsageStatus::Active,
                        'danger' => UsageStatus::Revoked,
                    ]),
                Tables\Columns\TextColumn::make('registered_at')
                    ->label(__('laravel-licensing-filament-manager::license-usage.fields.registered_at'))
                    ->dateTime('Y-m-d H:i:s'),
                Tables\Columns\TextColumn::make('last_seen_at')
                    ->label(__('laravel-licensing-filament-manager::license-usage.fields.last_seen_at'))
                    ->dateTime('Y-m-d H:i:s')
                    ->color(fn ($state) => $state && $state->diffInDays() > 7 ? 'warning' : 'success'),
            ])
            ->paginated(false);
    }
}
