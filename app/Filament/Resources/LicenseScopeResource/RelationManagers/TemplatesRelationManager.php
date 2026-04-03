<?php

namespace App\Filament\Resources\LicenseScopeResource\RelationManagers;

use App\Models\ContentEncryptionKey;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use LucaLongo\LaravelLicensingFilamentManager\Filament\Resources\LicenseScopeResource\RelationManagers\TemplatesRelationManager as BaseTemplatesRelationManager;
use LucaLongo\Licensing\Models\LicenseScope;
use LucaLongo\Licensing\Models\LicenseTemplate;

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

                        Forms\Components\Select::make('content_encryption_key_id')
                            ->label('加密金鑰')
                            ->options(ContentEncryptionKey::pluck('name', 'id'))
                            ->searchable()
                            ->preload()
                            ->required()
                            ->helperText('選擇此範本對應的素材加密金鑰（用 artisan content-key:create 建立）'),

                        Forms\Components\Hidden::make('tier_level')
                            ->default(1),

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

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('laravel-licensing-filament-manager::license-template.fields.name'))
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('content_encryption_key_id')
                    ->label('加密金鑰')
                    ->formatStateUsing(fn ($state) => $state ? ContentEncryptionKey::find($state)?->name : null)
                    ->badge()
                    ->color('info')
                    ->placeholder('未設定'),

                Tables\Columns\IconColumn::make('is_active')
                    ->label(__('laravel-licensing-filament-manager::license-template.fields.is_active'))
                    ->boolean(),

                Tables\Columns\TextColumn::make('license_duration_days')
                    ->label(__('laravel-licensing-filament-manager::license-template.fields.license_duration_days'))
                    ->formatStateUsing(fn ($state) => $state ? __('laravel-licensing-filament-manager::license-template.days', ['count' => $state]) : '∞'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label(__('laravel-licensing-filament-manager::license-template.actions.create'))
                    ->using(function (array $data, RelationManager $livewire): LicenseTemplate {
                        /** @var LicenseScope $scope */
                        $scope = $livewire->getOwnerRecord();

                        $preparedData = [
                            ...$livewire->prepareTemplatePayload($data),
                            'license_scope_id' => $scope->getKey(),
                        ];

                        return LicenseTemplate::create($preparedData);
                    }),
            ])
            ->recordActions([
                ViewAction::make()
                    ->label(__('laravel-licensing-filament-manager::common.actions.view')),
                EditAction::make()
                    ->label(__('laravel-licensing-filament-manager::common.actions.edit'))
                    ->using(function (LicenseTemplate $record, array $data, RelationManager $livewire): LicenseTemplate {
                        $record->update($livewire->prepareTemplatePayload($data));

                        return $record->refresh();
                    }),
                DeleteAction::make()
                    ->label(__('laravel-licensing-filament-manager::common.actions.delete')),
            ]);
    }
}
