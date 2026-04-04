<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LicenseResource\Pages;
use App\Filament\Resources\LicenseResource\RelationManagers\UsagesRelationManager;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use LucaLongo\LaravelLicensingFilamentManager\Filament\Resources\LicenseResource as BaseLicenseResource;
use LucaLongo\Licensing\Enums\LicenseStatus;
use LucaLongo\Licensing\Models\License;
use LucaLongo\Licensing\Models\LicenseScope;

/**
 * MVP 精簡版 LicenseResource。
 * 啟用由 App API 自動處理，已啟用的授權限制編輯範圍。
 */
class LicenseResource extends BaseLicenseResource
{
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
                            ->relationship('scope', 'name')
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
                            ->hidden(fn ($livewire) => method_exists($livewire, 'getOwnerRecord')),

                        Forms\Components\Select::make('template_id')
                            ->label(__('laravel-licensing-filament-manager::license.fields.template'))
                            ->preload()
                            ->searchable()
                            ->required()
                            ->live()
                            ->disabled(fn (?License $record) => $isActivated($record))
                            ->options(function (callable $get, ?License $record) {
                                $scopeId = $get('license_scope_id') ?? $record?->license_scope_id;
                                if (! $scopeId) {
                                    return [];
                                }
                                $scope = LicenseScope::find($scopeId);
                                if (! $scope) {
                                    return [];
                                }
                                $query = $scope->templates()->orderedByTier();
                                if (! $record?->template_id) {
                                    $query->active();
                                }

                                return $query->pluck('name', 'id')->toArray();
                            })
                            ->afterStateUpdated(function ($state, callable $set): void {
                                if (! $state) {
                                    return;
                                }
                                $templateModel = config('licensing.models.license_template');
                                $template = $templateModel::find($state);
                                if (! $template || ! $template->license_duration_days) {
                                    return;
                                }
                                $set('expires_at', now()->addDays($template->license_duration_days)->format('Y-m-d H:i:s'));
                            })
                            ->helperText(__('laravel-licensing-filament-manager::license.help.template')),

                        Forms\Components\Select::make('status')
                            ->label(__('laravel-licensing-filament-manager::license.fields.status'))
                            ->options(function (?License $record) {
                                // 移除寬限期選項
                                $options = collect(LicenseStatus::cases())
                                    ->filter(fn (LicenseStatus $s) => $s !== LicenseStatus::Grace)
                                    ->mapWithKeys(fn (LicenseStatus $s) => [$s->value => $s->name]);

                                if (! $record || $record->status === LicenseStatus::Pending) {
                                    return $options->toArray();
                                }

                                // 已啟用：不能改回待啟用，不能手動設為已到期（自動判斷）
                                return $options
                                    ->filter(fn ($label, $value) => ! in_array($value, [
                                        LicenseStatus::Pending->value,
                                        LicenseStatus::Expired->value,
                                    ]))
                                    ->toArray();
                            })
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

                                Forms\Components\DateTimePicker::make('expires_at')
                                    ->label(__('laravel-licensing-filament-manager::license.fields.expires_at'))
                                    ->displayFormat('d/m/Y H:i')
                                    ->required()
                                    ->disabled(fn (?License $record) => $isActivated($record))
                                    ->helperText('選擇範本後自動帶入，可手動調整。不論何時啟用，到期時間固定不變。'),
                            ])
                            ->columns(),

                        Section::make(__('laravel-licensing-filament-manager::license.form.usage_statistics'))
                            ->schema([
                                TextEntry::make('usages_count')
                                    ->label(__('laravel-licensing-filament-manager::license.fields.usages'))
                                    ->state(fn (?License $record) => $record?->usages()->count() ?? 0),

                                TextEntry::make('remaining_usages')
                                    ->label(__('laravel-licensing-filament-manager::license.fields.remaining_usages'))
                                    ->state(fn (?License $record) => $record ? max(0, $record->max_usages - $record->usages()->count()) : 0),
                            ])
                            ->columns(2)
                            ->hiddenOn('create'),

                        Section::make(__('laravel-licensing-filament-manager::license.form.security'))
                            ->schema([
                                TextEntry::make('retrieval_status')
                                    ->label(__('laravel-licensing-filament-manager::license.fields.key_visibility'))
                                    ->state(function (?License $record) {
                                        if (! $record) {
                                            return __('laravel-licensing-filament-manager::license.security.key_not_yet_generated');
                                        }

                                        return $record->canRetrieveKey()
                                            ? __('laravel-licensing-filament-manager::license.security.key_retrievable')
                                            : __('laravel-licensing-filament-manager::license.security.key_not_retrievable');
                                    })
                                    ->hidden(fn (?License $record) => $record === null),
                            ])
                            ->hidden(fn (?License $record) => $record === null),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label(__('laravel-licensing-filament-manager::license.fields.id'))
                    ->searchable()
                    ->copyable()
                    ->sortable()
                    ->limit(8)
                    ->description(fn (License $record) => $record->template?->name),

                Tables\Columns\TextColumn::make('scope.name')
                    ->label(__('laravel-licensing-filament-manager::license.fields.license_scope'))
                    ->searchable()
                    ->sortable()
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('status')
                    ->label(__('laravel-licensing-filament-manager::license.fields.status'))
                    ->badge()
                    ->colors([
                        'warning' => LicenseStatus::Pending,
                        'success' => LicenseStatus::Active,
                        'danger' => [LicenseStatus::Expired, LicenseStatus::Suspended, LicenseStatus::Cancelled],
                    ]),

                Tables\Columns\TextColumn::make('usages_count')
                    ->label(__('laravel-licensing-filament-manager::license.fields.usages'))
                    ->counts('usages')
                    ->formatStateUsing(fn (int $state, License $record) => "{$state}/{$record->max_usages}")
                    ->sortable()
                    ->badge()
                    ->color(fn (int $state, License $record) => $state >= $record->max_usages ? 'danger' : 'success'),

                Tables\Columns\TextColumn::make('activated_at')
                    ->label(__('laravel-licensing-filament-manager::license.fields.activated_at'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->placeholder(__('laravel-licensing-filament-manager::common.not_activated')),

                Tables\Columns\TextColumn::make('expires_at')
                    ->label(__('laravel-licensing-filament-manager::license.fields.expires_at'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->color(fn ($state) => $state && $state->isPast() ? 'danger' : 'success'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('laravel-licensing-filament-manager::common.created_at'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('laravel-licensing-filament-manager::license.fields.status'))
                    ->options(LicenseStatus::class)
                    ->multiple(),

                Tables\Filters\SelectFilter::make('license_scope_id')
                    ->label(__('laravel-licensing-filament-manager::license.fields.license_scope'))
                    ->relationship('scope', 'name')
                    ->searchable()
                    ->preload(),

                Tables\Filters\SelectFilter::make('template_id')
                    ->label(__('laravel-licensing-filament-manager::license.fields.template'))
                    ->relationship('template', 'name')
                    ->searchable()
                    ->preload(),

                Tables\Filters\TernaryFilter::make('expired')
                    ->label(__('laravel-licensing-filament-manager::license.filters.expired'))
                    ->queries(
                        true: fn (Builder $query) => $query->where('expires_at', '<', now()),
                        false: fn (Builder $query) => $query->where('expires_at', '>=', now()),
                    ),

                Tables\Filters\Filter::make('expiring_soon')
                    ->label(__('laravel-licensing-filament-manager::license.filters.expiring_soon'))
                    ->query(fn (Builder $query) => $query->whereBetween('expires_at', [now(), now()->addDays(30)])),
            ])
            ->recordActions([
                ViewAction::make()
                    ->label(__('laravel-licensing-filament-manager::common.actions.view')),

                EditAction::make()
                    ->label(__('laravel-licensing-filament-manager::common.actions.edit')),

                Action::make('suspend')
                    ->label(__('laravel-licensing-filament-manager::license.actions.suspend'))
                    ->icon('heroicon-o-pause')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn (License $record) => $record->status === LicenseStatus::Active)
                    ->action(function (License $record): void {
                        $record->suspend();
                        Notification::make()
                            ->title(__('laravel-licensing-filament-manager::license.notifications.suspended'))
                            ->warning()
                            ->send();
                    }),

                Action::make('show_key')
                    ->label(__('laravel-licensing-filament-manager::license.actions.show_key'))
                    ->icon('heroicon-o-key')
                    ->visible(fn (License $record) => $record->canRetrieveKey())
                    ->action(function (License $record): void {
                        $key = $record->retrieveKey();
                        $formatted = $key ? implode('-', str_split(strtoupper($key), 5)) : null;
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
            ->defaultSort('created_at', 'desc');
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
