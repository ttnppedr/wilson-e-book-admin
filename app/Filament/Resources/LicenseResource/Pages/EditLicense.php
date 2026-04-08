<?php

namespace App\Filament\Resources\LicenseResource\Pages;

use App\Filament\Resources\LicenseResource;
use Filament\Resources\Pages\EditRecord;
use LucaLongo\Licensing\Enums\LicenseStatus;

class EditLicense extends EditRecord
{
    protected static string $resource = LicenseResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $current = $this->getRecord()->status;
        $new = LicenseStatus::from($data['status']);

        // 已啟用後不允許改回待啟用或已到期
        if ($current !== LicenseStatus::Pending && in_array($new, [LicenseStatus::Pending, LicenseStatus::Expired], true)) {
            $data['status'] = $current->value;
        }

        return $data;
    }
}
