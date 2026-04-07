<?php

namespace App\Filament\Resources\LicenseResource\Pages;

use App\Filament\Resources\LicenseResource;
use App\Models\LicenseScope;
use App\Services\LicenseKeyGenerator;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use App\Models\License;

class CreateLicense extends CreateRecord
{
    protected static string $resource = LicenseResource::class;

    protected ?string $generatedKey = null;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function handleRecordCreation(array $data): License
    {
        /** @var LicenseScope $scope */
        $scope = LicenseScope::findOrFail($data['license_scope_id']);

        $record = $scope->createLicense($data);

        $this->generatedKey = $record->temporaryLicenseKey;

        return $record->refresh();
    }

    protected function afterCreate(): void
    {
        if ($this->generatedKey) {
            $formatted = LicenseKeyGenerator::format($this->generatedKey);
            Notification::make()
                ->title(__('laravel-licensing-filament-manager::license.notifications.key_generated'))
                ->body(__('laravel-licensing-filament-manager::license.notifications.key_value', ['key' => $formatted]))
                ->success()
                ->persistent()
                ->send();
        } else {
            Notification::make()
                ->title(__('laravel-licensing-filament-manager::license.notifications.created'))
                ->success()
                ->send();
        }
    }
}
