<?php

namespace App\Filament\Resources\LicenseResource\Pages;

use App\Filament\Resources\LicenseResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use LucaLongo\Licensing\Enums\LicenseStatus;

class EditLicense extends EditRecord
{
    protected static string $resource = LicenseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->label(__('laravel-licensing-filament-manager::common.actions.delete'))
                ->visible(fn () => $this->record->status === LicenseStatus::Pending),
        ];
    }
}
