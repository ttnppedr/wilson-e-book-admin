<?php

namespace App\Filament\Resources\LicenseScopeResource\Pages;

use App\Filament\Resources\LicenseScopeResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use LucaLongo\LaravelLicensingFilamentManager\Filament\Resources\LicenseScopeResource\Pages\EditLicenseScope as BaseEditLicenseScope;
use LucaLongo\Licensing\Models\LicenseScope;

class EditLicenseScope extends BaseEditLicenseScope
{
    protected static string $resource = LicenseScopeResource::class;

    /**
     * 編輯頁的 header 也有一顆 DeleteAction（vendor 的 getHeaderActions()），
     * 與列表頁的那顆是兩條獨立的刪除路徑，必須各自守門。
     *
     * 這裡改寫 parent 的結果而非整段複製 vendor 實作：vendor 日後新增 header
     * action（例如 rotate_keys 之外的功能）會自動沿用，不必跟著同步——CLAUDE.md
     * 已經有三處「複製 vendor 再就地改」的升級負擔，不再多加一處。
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        /** @var LicenseScope $record */
        $record = $this->record;

        return array_map(
            fn (Action $action): Action => $action instanceof DeleteAction
                ? $action->visible(fn (): bool => $record->licenses()->doesntExist())
                : $action,
            parent::getHeaderActions(),
        );
    }
}
