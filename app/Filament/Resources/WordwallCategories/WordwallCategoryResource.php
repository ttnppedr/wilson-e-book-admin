<?php

namespace App\Filament\Resources\WordwallCategories;

use App\Filament\Resources\WordwallCategories\Pages\ManageWordwallCategories;
use App\Models\WordwallCategory;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class WordwallCategoryResource extends Resource
{
    protected static ?string $model = WordwallCategory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static string|UnitEnum|null $navigationGroup = '遊戲管理';

    protected static ?string $pluralLabel = '遊戲分類';

    protected static ?string $label = '遊戲分類';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('名稱')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(255),
                FileUpload::make('image_path')
                    ->label('圖片')
                    ->image()
                    ->required()
                    ->disk(config('filesystems.default'))
                    ->visibility('public')
                    ->directory('wordwall-categories')
                    ->maxSize(2048),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('image_path')
                    ->label('圖片')
                    ->disk(config('filesystems.default')),
                TextColumn::make('name')
                    ->label('名稱')
                    ->searchable(),
                TextColumn::make('wordwalls_count')
                    ->label('遊戲數')
                    ->counts('wordwalls'),
                TextColumn::make('sort')
                    ->label('排序')
                    ->sortable(),
            ])
            ->defaultSort('sort')
            ->reorderable('sort')
            ->paginated(false)
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageWordwallCategories::route('/'),
        ];
    }
}
