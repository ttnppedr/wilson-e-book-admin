<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LicenseScopeResource\Pages;
use App\Filament\Resources\LicenseScopeResource\RelationManagers\LicensesRelationManager;
use App\Models\ContentEncryptionKey;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use LucaLongo\LaravelLicensingFilamentManager\Filament\Resources\LicenseScopeResource as BaseLicenseScopeResource;
use LucaLongo\Licensing\Models\LicenseScope;

/**
 * MVP 精簡版 LicenseScopeResource。
 * 隱藏 description、grace_days、key_rotation 區塊、meta 等 MVP 不需要的欄位。
 */
class LicenseScopeResource extends BaseLicenseScopeResource
{
    public static function getNavigationBadge(): ?string
    {
        return null;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns()
            ->schema([
                Section::make(__('laravel-licensing-filament-manager::license-scope.form.basic_information'))
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label(__('laravel-licensing-filament-manager::license-scope.fields.name'))
                            ->required()
                            ->maxLength(255),

                        Forms\Components\Toggle::make('is_active')
                            ->label(__('laravel-licensing-filament-manager::license-scope.fields.is_active'))
                            ->default(true),
                    ]),

                Section::make(__('laravel-licensing-filament-manager::license-scope.form.default_license_settings'))
                    ->columns()
                    ->schema([
                        Forms\Components\Select::make('content_encryption_key_id')
                            ->label('內容加密金鑰')
                            ->options(ContentEncryptionKey::pluck('name', 'id'))
                            ->native(false)
                            ->searchable()
                            ->preload()
                            ->required()
                            ->disabled(fn (?LicenseScope $record): bool => $record !== null)
                            ->dehydrated()
                            ->helperText(fn (?LicenseScope $record): string => $record !== null
                                ? '建立後不可變更'
                                : '此產品/版本使用的內容加密金鑰'),

                        Forms\Components\TextInput::make('default_max_usages')
                            ->label(__('laravel-licensing-filament-manager::license-scope.fields.default_max_usages'))
                            ->numeric()
                            ->minValue(1)
                            ->default(1)
                            ->required(),

                        Forms\Components\TextInput::make('default_duration_days')
                            ->label(__('laravel-licensing-filament-manager::license-scope.fields.default_duration_days'))
                            ->numeric()
                            ->minValue(1)
                            ->helperText(__('laravel-licensing-filament-manager::license-scope.fields.default_duration_days_help')),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\IconColumn::make('is_active')
                    ->label(__('laravel-licensing-filament-manager::license-scope.fields.is_active'))
                    ->boolean()
                    ->sortable(),

                Tables\Columns\TextColumn::make('name')
                    ->label(__('laravel-licensing-filament-manager::license-scope.fields.name'))
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('licenses_count')
                    ->label(__('laravel-licensing-filament-manager::license-scope.fields.licenses_count'))
                    ->counts('licenses')
                    ->sortable()
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('active_licenses_count')
                    ->label(__('laravel-licensing-filament-manager::license-scope.fields.active_licenses_count'))
                    ->state(fn (LicenseScope $record) => $record->licenses()->where('status', 'active')->count())
                    ->badge()
                    ->color('success'),
            ])
            ->recordActions([
                ViewAction::make()
                    ->label(__('laravel-licensing-filament-manager::common.actions.view')),
                EditAction::make()
                    ->label(__('laravel-licensing-filament-manager::common.actions.edit')),
                DeleteAction::make()
                    ->label(__('laravel-licensing-filament-manager::common.actions.delete')),
            ])
            ->toolbarActions([])
            ->defaultSort('created_at', 'desc')
            ->poll('60s');
    }

    public static function getRelations(): array
    {
        return [
            LicensesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLicenseScopes::route('/'),
            'create' => Pages\CreateLicenseScope::route('/create'),
            'view' => Pages\ViewLicenseScope::route('/{record}'),
            'edit' => Pages\EditLicenseScope::route('/{record}/edit'),
        ];
    }
}
