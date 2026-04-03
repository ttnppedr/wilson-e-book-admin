<?php

namespace App\Filament\Resources\LicenseScopeResource\RelationManagers;

use App\Filament\Resources\LicenseResource;
use Filament\Actions\CreateAction;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use LucaLongo\LaravelLicensingFilamentManager\Filament\Resources\LicenseScopeResource\RelationManagers\LicensesRelationManager as BaseLicensesRelationManager;

/**
 * 覆寫 vendor LicensesRelationManager，使用自訂的精簡表單（移除 licensable、meta 等）。
 */
class LicensesRelationManager extends BaseLicensesRelationManager
{
    public function form(Schema $schema): Schema
    {
        return LicenseResource::form($schema);
    }

    public function table(Table $table): Table
    {
        $configured = LicenseResource::table($table);

        // 在 RelationManager 中移除 scope 欄位（已在 Scope context 中）
        $columns = collect($configured->getColumns())
            ->filter(fn ($column) => $column->getName() !== 'scope.name')
            ->toArray();

        $filters = collect($configured->getFilters())
            ->filter(fn ($filter) => $filter->getName() !== 'license_scope_id')
            ->toArray();

        return $configured
            ->columns($columns)
            ->filters($filters)
            ->headerActions([
                CreateAction::make()
                    ->url(fn () => LicenseResource::getUrl('create')),
            ]);
    }

    public static function getTitle($ownerRecord, $pageClass): string
    {
        return __('laravel-licensing-filament-manager::license-scope.relations.licenses');
    }
}
