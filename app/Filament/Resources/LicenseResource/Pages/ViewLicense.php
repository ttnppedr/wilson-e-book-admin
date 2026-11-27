<?php

namespace App\Filament\Resources\LicenseResource\Pages;

use App\Filament\Resources\DiagnosticSessions\DiagnosticSessionResource;
use App\Filament\Resources\LicenseResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewLicense extends ViewRecord
{
    protected static string $resource = LicenseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('diagnostics')
                ->label('診斷紀錄')
                ->icon(Heroicon::OutlinedBugAnt)
                ->color('gray')
                ->url(fn (): string => DiagnosticSessionResource::getUrl('index', [
                    'filters' => ['license' => ['value' => $this->getRecord()->getKey()]],
                ])),
            EditAction::make(),
        ];
    }
}
