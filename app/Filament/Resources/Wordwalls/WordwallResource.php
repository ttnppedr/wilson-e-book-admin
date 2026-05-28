<?php

namespace App\Filament\Resources\Wordwalls;

use App\Filament\Resources\Wordwalls\Pages\ManageWordwalls;
use App\Models\Wordwall;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class WordwallResource extends Resource
{
    protected static ?string $model = Wordwall::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = '遊戲管理';

    protected static ?string $pluralLabel = 'Wordwall';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('resource_url')
                    ->label('Resource 網址')
                    ->required()
                    ->rules(['regex:#^https://wordwall\.net/#'])
                    ->validationMessages([
                        'regex' => '網址必須以 https://wordwall.net/ 開頭',
                    ])
                    ->unique(ignoreRecord: true)
                    ->maxLength(255)
                    ->placeholder('https://wordwall.net/resource/109915454'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('resource_url')
                    ->label('Resource 網址')
                    ->searchable(),
                TextColumn::make('sort')
                    ->label('排序')
                    ->sortable(),
            ])
            ->defaultSort('sort')
            ->reorderable('sort')
            ->paginated(false)
            ->recordActions([
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageWordwalls::route('/'),
        ];
    }
}
