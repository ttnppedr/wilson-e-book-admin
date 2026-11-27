<?php

namespace App\Filament\Resources\DiagnosticSessions\Pages;

use App\Filament\Resources\DiagnosticSessions\DiagnosticSessionResource;
use App\Services\DiagnosticTimelineBuilder;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Livewire\Attributes\Locked;

class ViewDiagnosticSession extends ViewRecord
{
    protected static string $resource = DiagnosticSessionResource::class;

    /**
     * 時間軸目前載入的事件筆數（從最新的往回算）。
     *
     * Locked：只能透過 loadEarlier() 以固定步幅增加，client 不能自行改成任意大的
     * 數字讓伺服器一次渲染整個 session。
     */
    #[Locked]
    public int $eventLimit = DiagnosticTimelineBuilder::PAGE_SIZE;

    public function getTitle(): string
    {
        $record = $this->getRecord();

        return '診斷紀錄：'.($record->license?->name ?? $record->fingerprint);
    }

    public function loadEarlier(): void
    {
        $this->eventLimit += DiagnosticTimelineBuilder::PAGE_SIZE;
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getInfolistContentComponent(),
                Section::make('時間軸')
                    ->description('時間為台灣時間（裝置自報，可能與實際時間有落差）；事件依 App 給的序號排列。')
                    ->schema([
                        View::make('filament.diagnostics.timeline')
                            ->viewData(fn (): array => app(DiagnosticTimelineBuilder::class)
                                ->build($this->getRecord(), $this->eventLimit)),
                    ]),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
