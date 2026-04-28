<?php

namespace App\Filament\Resources;

use App\Enums\LicenseStatusLabel;
use App\Filament\Resources\LicenseResource\Pages;
use App\Filament\Resources\LicenseResource\RelationManagers\UsagesRelationManager;
use App\Models\License;
use App\Services\LicenseKeyGenerator;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use LucaLongo\LaravelLicensingFilamentManager\Filament\Resources\LicenseResource as BaseLicenseResource;
use LucaLongo\Licensing\Enums\LicenseStatus;
use LucaLongo\Licensing\Models\LicenseScope;

/**
 * MVP 精簡版 LicenseResource。
 * 啟用由 App API 自動處理，已啟用的授權限制編輯範圍。
 */
class LicenseResource extends BaseLicenseResource
{
    public static function getNavigationBadge(): ?string
    {
        return null;
    }

    public static function form(Schema $schema): Schema
    {
        $isActivated = fn (?License $record): bool => $record !== null && $record->status !== LicenseStatus::Pending;

        return $schema
            ->schema([
                Section::make(__('laravel-licensing-filament-manager::license.form.basic_information'))
                    ->columns(1)
                    ->schema([
                        Forms\Components\Select::make('license_scope_id')
                            ->label(__('laravel-licensing-filament-manager::license.fields.license_scope'))
                            ->relationship('scope', 'name', fn (Builder $query) => $query->where('is_active', true))
                            ->native(false)
                            ->searchable()
                            ->preload()
                            ->required()
                            ->live()
                            ->disabled(fn (?License $record) => $isActivated($record))
                            ->default(function ($livewire) {
                                if (method_exists($livewire, 'getOwnerRecord')) {
                                    return $livewire->getOwnerRecord()->id;
                                }

                                return null;
                            })
                            ->hidden(fn ($livewire) => method_exists($livewire, 'getOwnerRecord'))
                            ->afterStateUpdated(function ($state, callable $set, callable $get): void {
                                if (! $state || $get('is_perpetual')) {
                                    return;
                                }
                                $scope = LicenseScope::find($state);
                                if (! $scope || ! $scope->default_duration_days) {
                                    return;
                                }
                                $set('expires_at', now()->addDays($scope->default_duration_days)->format('Y-m-d H:i:s'));
                            }),

                        Forms\Components\TextInput::make('name')
                            ->label(__('laravel-licensing-filament-manager::license.fields.name'))
                            ->required()
                            ->maxLength(255)
                            ->helperText(__('laravel-licensing-filament-manager::license.help.name')),

                        Forms\Components\Select::make('status')
                            ->label(__('laravel-licensing-filament-manager::license.fields.status'))
                            ->native(false)
                            ->options(
                                collect(LicenseStatusLabel::cases())
                                    ->mapWithKeys(fn (LicenseStatusLabel $s) => [$s->value => $s->getLabel()])
                                    ->toArray()
                            )
                            ->required()
                            ->default(LicenseStatus::Pending->value)
                            ->hiddenOn('create'),
                    ]),

                Grid::make()
                    ->columns(1)
                    ->schema([
                        Section::make(__('laravel-licensing-filament-manager::license.form.dates_activation'))
                            ->schema([
                                Forms\Components\DateTimePicker::make('activated_at')
                                    ->label(__('laravel-licensing-filament-manager::license.fields.activated_at'))
                                    ->displayFormat('d/m/Y H:i')
                                    ->disabled()
                                    ->hiddenOn('create'),

                                Forms\Components\Toggle::make('is_perpetual')
                                    ->label('永久授權')
                                    ->dehydrated(false)
                                    ->live()
                                    ->default(false)
                                    ->disabled(fn (?License $record) => $isActivated($record))
                                    ->afterStateHydrated(function (Forms\Components\Toggle $component, ?License $record): void {
                                        $component->state($record !== null && $record->expires_at === null);
                                    })
                                    ->afterStateUpdated(function ($state, callable $set): void {
                                        if ($state) {
                                            $set('expires_at', null);
                                        }
                                    }),

                                Forms\Components\DateTimePicker::make('expires_at')
                                    ->label(__('laravel-licensing-filament-manager::license.fields.expires_at'))
                                    ->displayFormat('d/m/Y H:i')
                                    ->disabled(fn (callable $get, ?License $record) => $isActivated($record) || $get('is_perpetual'))
                                    ->dehydrated()
                                    ->helperText('選擇授權範圍後自動帶入，可手動調整。勾選「永久授權」即留空，不論何時啟用永不到期。不論何時啟用，到期時間固定不變。'),
                            ])
                            ->columns(),

                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('laravel-licensing-filament-manager::license.fields.name'))
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('scope.name')
                    ->label(__('laravel-licensing-filament-manager::license.fields.license_scope'))
                    ->sortable()
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('status')
                    ->label(__('laravel-licensing-filament-manager::license.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (LicenseStatus $state) => LicenseStatusLabel::from($state->value)->getLabel())
                    ->color(fn (LicenseStatus $state) => LicenseStatusLabel::from($state->value)->getColor()),

                Tables\Columns\TextColumn::make('activated_at')
                    ->label(__('laravel-licensing-filament-manager::license.fields.activated_at'))
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable()
                    ->placeholder(__('laravel-licensing-filament-manager::common.not_activated')),

                Tables\Columns\TextColumn::make('expires_at')
                    ->label(__('laravel-licensing-filament-manager::license.fields.expires_at'))
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable()
                    ->color(fn ($state) => $state && $state->isPast() ? 'danger' : 'success'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('laravel-licensing-filament-manager::license.fields.status'))
                    ->options(LicenseStatusLabel::class)
                    ->multiple(),

                Tables\Filters\SelectFilter::make('license_scope_id')
                    ->label(__('laravel-licensing-filament-manager::license.fields.license_scope'))
                    ->relationship('scope', 'name')
                    ->searchable()
                    ->preload(),

                Tables\Filters\TernaryFilter::make('expired')
                    ->label(__('laravel-licensing-filament-manager::license.filters.expired'))
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('expires_at')->where('expires_at', '<', now()),
                        false: fn (Builder $query) => $query->where(
                            fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>=', now())
                        ),
                    ),

                Tables\Filters\Filter::make('expiring_soon')
                    ->label(__('laravel-licensing-filament-manager::license.filters.expiring_soon').'（30 天內到期）')
                    ->query(fn (Builder $query) => $query->whereBetween('expires_at', [now(), now()->addDays(30)])),
            ])
            ->recordActions([
                ViewAction::make()
                    ->label(__('laravel-licensing-filament-manager::common.actions.view')),

                EditAction::make()
                    ->label(__('laravel-licensing-filament-manager::common.actions.edit')),

                Action::make('show_key')
                    ->label(__('laravel-licensing-filament-manager::license.actions.show_key'))
                    ->icon('heroicon-o-key')
                    ->visible(fn (License $record) => $record->canRetrieveKey())
                    ->action(function (License $record): void {
                        $key = $record->retrieveKey();
                        $formatted = $key ? LicenseKeyGenerator::format($key) : null;
                        Notification::make()
                            ->title(__('laravel-licensing-filament-manager::license.notifications.key_retrieved'))
                            ->body($formatted
                                ? __('laravel-licensing-filament-manager::license.notifications.key_value', ['key' => $formatted])
                                : __('laravel-licensing-filament-manager::license.notifications.key_unavailable'))
                            ->success()
                            ->persistent()
                            ->send();
                    }),

                DeleteAction::make()
                    ->label(__('laravel-licensing-filament-manager::common.actions.delete'))
                    ->visible(fn (License $record) => $record->status === LicenseStatus::Pending),
            ])
            ->toolbarActions([])
            ->defaultSort('created_at', 'desc')
            ->poll('60s');
    }

    public static function getRelations(): array
    {
        return [
            UsagesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLicenses::route('/'),
            'create' => Pages\CreateLicense::route('/create'),
            'view' => Pages\ViewLicense::route('/{record}'),
            'edit' => Pages\EditLicense::route('/{record}/edit'),
        ];
    }
}
