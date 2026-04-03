<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LicenseScopeResource\Pages;
use App\Filament\Resources\LicenseScopeResource\RelationManagers\TemplatesRelationManager;
use Filament\Forms;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use LucaLongo\LaravelLicensingFilamentManager\Filament\Resources\LicenseScopeResource as BaseLicenseScopeResource;
use LucaLongo\LaravelLicensingFilamentManager\Filament\Resources\LicenseScopeResource\RelationManagers\LicensesRelationManager;
use LucaLongo\LaravelLicensingFilamentManager\Filament\Resources\LicenseScopeResource\RelationManagers\SigningKeysRelationManager;
use LucaLongo\Licensing\Models\LicenseScope;

/**
 * MVP 精簡版 LicenseScopeResource。
 * 隱藏 description、grace_days、key_rotation 區塊、meta 等 MVP 不需要的欄位。
 */
class LicenseScopeResource extends BaseLicenseScopeResource
{
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
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (?string $state, Set $set): void {
                                if ($state) {
                                    $set('slug', str($state)->slug()->toString());
                                }
                            }),

                        Forms\Components\TextInput::make('slug')
                            ->label(__('laravel-licensing-filament-manager::license-scope.fields.slug'))
                            ->required()
                            ->maxLength(255)
                            ->unique(LicenseScope::class, 'slug', ignoreRecord: true)
                            ->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/'),

                        Forms\Components\TextInput::make('identifier')
                            ->label(__('laravel-licensing-filament-manager::license-scope.fields.identifier'))
                            ->maxLength(255)
                            ->unique(LicenseScope::class, 'identifier', ignoreRecord: true)
                            ->helperText(__('laravel-licensing-filament-manager::license-scope.fields.identifier_help')),

                        Forms\Components\Toggle::make('is_active')
                            ->label(__('laravel-licensing-filament-manager::license-scope.fields.is_active'))
                            ->default(true),
                    ]),

                Section::make(__('laravel-licensing-filament-manager::license-scope.form.default_license_settings'))
                    ->columns()
                    ->schema([
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

    public static function getRelations(): array
    {
        return [
            TemplatesRelationManager::class,
            SigningKeysRelationManager::class,
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
