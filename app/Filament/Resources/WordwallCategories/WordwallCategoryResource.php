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
                    // 物件維持 private：對外由 App 經轉址端點取 presigned 短效網址，
                    // 後台預覽則由 Filament 對 private disk 自動簽發 temporaryUrl。
                    ->visibility('private')
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
                    ->disk(config('filesystems.default'))
                    // ImageColumn 預設 shouldCheckFileExistence = true，會在產生預覽網址前
                    // 先 $storage->exists()——對 s3 disk 那是**一次真實的 HeadObject 網路
                    // 往返，且逐列序列化執行**。本表格 ->paginated(false) 沒有上限，又有
                    // ->searchable()（每個按鍵一次 Livewire 往返、整表重繪），成本因此是
                    // 「分類數 × S3 RTT × 每次互動」。本機 MinIO 同主機約 1.3ms/列看不出來，
                    // 正式站對真實 S3 是 15–40ms/列，量一多就是好幾秒。
                    //
                    // 關掉是安全的：image_path 在表單是 ->required()，且 model 的
                    // updated/deleted hook 會清掉被取代與被刪除的圖檔，「有 image_path 但
                    // S3 上沒檔」屬於異常狀態，不值得為它對每一列付一次網路往返。代價僅是
                    // 該情況下顯示破圖而非空白。
                    ->checkFileExistence(false)
                    // private disk：Filament 自動以 temporaryUrl 簽發後台預覽網址（過期對齊整點）。
                    // temporaryUrl() 是本地 HMAC 簽章（實測 0.3ms），不涉及網路往返。
                    ->visibility('private'),
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
