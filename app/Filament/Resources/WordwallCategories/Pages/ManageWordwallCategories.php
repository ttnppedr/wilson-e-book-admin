<?php

namespace App\Filament\Resources\WordwallCategories\Pages;

use App\Filament\Resources\WordwallCategories\WordwallCategoryResource;
use App\Models\WordwallCategory;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageWordwallCategories extends ManageRecords
{
    protected static string $resource = WordwallCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->mutateDataUsing(function (array $data): array {
                    $data['sort'] = (WordwallCategory::max('sort') ?? 0) + 1;

                    return $data;
                }),
        ];
    }
}
