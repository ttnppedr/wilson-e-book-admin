<?php

namespace App\Filament\Resources\DiagnosticSessions\Pages;

use App\Filament\Resources\DiagnosticSessions\DiagnosticSessionResource;
use Filament\Resources\Pages\ListRecords;

class ListDiagnosticSessions extends ListRecords
{
    protected static string $resource = DiagnosticSessionResource::class;
}
