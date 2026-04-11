<?php

namespace App\Filament\Resources\Wordwalls\Pages;

use App\Filament\Resources\Wordwalls\WordwallResource;
use App\Models\Wordwall;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageWordwalls extends ManageRecords
{
    protected static string $resource = WordwallResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->mutateDataUsing(function (array $data): array {
                    $data['sort'] = (Wordwall::max('sort') ?? 0) + 1;

                    return $data;
                }),
        ];
    }
}
