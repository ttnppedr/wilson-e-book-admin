<?php

namespace App\Filament\Resources\LicenseResource\Pages;

use App\Filament\Resources\LicenseResource;
use Filament\Notifications\Notification;
use LucaLongo\LaravelLicensingFilamentManager\Filament\Resources\LicenseResource\Pages\CreateLicense as BaseCreateLicense;

class CreateLicense extends BaseCreateLicense
{
    protected static string $resource = LicenseResource::class;

    protected function afterCreate(): void
    {
        if ($this->generatedKey) {
            $formatted = implode('-', str_split(strtoupper($this->generatedKey), 5));
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
