<?php

namespace App\Filament\Resources\LicenseScopeResource\RelationManagers;

use Filament\Forms;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use LucaLongo\LaravelLicensingFilamentManager\Filament\Resources\LicenseScopeResource\RelationManagers\TemplatesRelationManager as BaseTemplatesRelationManager;

/**
 * MVP 精簡版 TemplatesRelationManager。
 * 隱藏 parent_template、slug、trial、grace、base_configuration、meta 等欄位。
 */
class TemplatesRelationManager extends BaseTemplatesRelationManager
{
    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('laravel-licensing-filament-manager::license-scope.relations.templates');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->schema([
                Section::make(__('laravel-licensing-filament-manager::license-template.form.details'))
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label(__('laravel-licensing-filament-manager::license-template.fields.name'))
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true),

                        Forms\Components\TextInput::make('tier_level')
                            ->label(__('laravel-licensing-filament-manager::license-template.fields.tier_level'))
                            ->numeric()
                            ->minValue(1)
                            ->required(),

                        Forms\Components\Toggle::make('is_active')
                            ->label(__('laravel-licensing-filament-manager::license-template.fields.is_active'))
                            ->default(true)
                            ->columnSpanFull(),
                    ]),

                Section::make(__('laravel-licensing-filament-manager::license-template.form.durations'))
                    ->schema([
                        Forms\Components\TextInput::make('license_duration_days')
                            ->label(__('laravel-licensing-filament-manager::license-template.fields.license_duration_days'))
                            ->numeric()
                            ->minValue(1)
                            ->nullable()
                            ->helperText(__('laravel-licensing-filament-manager::license-template.help.license_duration_days')),
                    ]),

                Section::make(__('laravel-licensing-filament-manager::license-template.form.configuration'))
                    ->schema([
                        Forms\Components\KeyValue::make('features')
                            ->label(__('laravel-licensing-filament-manager::license-template.fields.features'))
                            ->keyLabel(__('laravel-licensing-filament-manager::common.key'))
                            ->valueLabel(__('laravel-licensing-filament-manager::common.value'))
                            ->helperText(__('laravel-licensing-filament-manager::license-template.help.features'))
                            ->formatStateUsing(fn ($state) => $this->formatArrayState($state))
                            ->dehydrateStateUsing(fn ($state) => $this->sanitizeArrayValue($state)),

                        Forms\Components\KeyValue::make('entitlements')
                            ->label(__('laravel-licensing-filament-manager::license-template.fields.entitlements'))
                            ->keyLabel(__('laravel-licensing-filament-manager::common.key'))
                            ->valueLabel(__('laravel-licensing-filament-manager::common.value'))
                            ->helperText(__('laravel-licensing-filament-manager::license-template.help.entitlements'))
                            ->formatStateUsing(fn ($state) => $this->formatArrayState($state))
                            ->dehydrateStateUsing(fn ($state) => $this->sanitizeArrayValue($state)),
                    ])
                    ->columns(1),
            ]);
    }
}
