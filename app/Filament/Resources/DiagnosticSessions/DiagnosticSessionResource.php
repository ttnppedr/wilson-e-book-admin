<?php

namespace App\Filament\Resources\DiagnosticSessions;

use App\Filament\Resources\DiagnosticSessions\Pages\ListDiagnosticSessions;
use App\Filament\Resources\DiagnosticSessions\Pages\ViewDiagnosticSession;
use App\Filament\Resources\LicenseResource;
use App\Models\DiagnosticSession;
use App\Services\DiagnosticTimelineBuilder;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use UnitEnum;

/**
 * App 診斷紀錄：白板 App 上傳的操作事件日誌，用來事後追查現場問題。
 *
 * 唯讀——資料全部由裝置上傳，後台只能檢視與刪除。內容視同不可信輸入，
 * 顯示時一律經過 escape。
 */
class DiagnosticSessionResource extends Resource
{
    protected static ?string $model = DiagnosticSession::class;

    protected static ?string $slug = 'diagnostics';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBugAnt;

    protected static string|UnitEnum|null $navigationGroup = '授權管理';

    protected static ?int $navigationSort = 90;

    protected static ?string $modelLabel = '診斷紀錄';

    protected static ?string $pluralModelLabel = '診斷紀錄';

    private const DATE_TIME_FORMAT = 'Y-m-d H:i:s';

    private const ENVIRONMENTS = [
        'prod' => '正式（prod）',
        'staging' => '測試（staging）',
        'dev' => '開發（dev）',
    ];

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('license');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('摘要')
                    ->columns(['default' => 2, 'lg' => 4])
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('license.name')
                            ->label('授權')
                            ->placeholder('（授權已刪除）')
                            ->url(fn (DiagnosticSession $record): ?string => static::licenseUrl($record)),
                        TextEntry::make('fingerprint')
                            ->label('裝置指紋')
                            ->fontFamily(FontFamily::Mono)
                            ->copyable(),
                        TextEntry::make('app_version')
                            ->label('App 版本／環境')
                            ->state(fn (DiagnosticSession $record): string => $record->app_version.'／'.($record->app_env ?? '未標示')),
                        TextEntry::make('device_model')
                            ->label('裝置型號／OS')
                            ->state(fn (DiagnosticSession $record): string => implode('／', array_filter([
                                $record->device_model ?? '未提供',
                                trim($record->platform.' '.($record->os_version ?? '')),
                            ]))),
                        TextEntry::make('started_at')
                            ->label('開始時間（裝置）')
                            ->dateTime(self::DATE_TIME_FORMAT, DiagnosticTimelineBuilder::DISPLAY_TIMEZONE),
                        TextEntry::make('last_event_at')
                            ->label('最後事件時間（裝置）')
                            ->dateTime(self::DATE_TIME_FORMAT, DiagnosticTimelineBuilder::DISPLAY_TIMEZONE),
                        TextEntry::make('last_received_at')
                            ->label('最後上傳時間（伺服器）')
                            ->dateTime(self::DATE_TIME_FORMAT, DiagnosticTimelineBuilder::DISPLAY_TIMEZONE),
                        TextEntry::make('duration')
                            ->label('持續時間')
                            ->state(fn (DiagnosticSession $record): ?string => static::formatDuration($record))
                            ->placeholder('—'),
                        TextEntry::make('last_error_name')
                            ->label('最後錯誤')
                            ->fontFamily(FontFamily::Mono)
                            ->placeholder('無'),
                        TextEntry::make('event_count')
                            ->label('事件數')
                            ->numeric(),
                        TextEntry::make('warn_count')
                            ->label('警告數')
                            ->badge()
                            ->color(fn (int $state): string => $state > 0 ? 'warning' : 'gray'),
                        TextEntry::make('error_count')
                            ->label('錯誤數')
                            ->badge()
                            ->color(fn (int $state): string => $state > 0 ? 'danger' : 'gray'),
                        TextEntry::make('ip')
                            ->label('上傳來源 IP')
                            ->placeholder('—'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('last_received_at')
                    ->label('最後上傳時間（伺服器）')
                    ->dateTime(self::DATE_TIME_FORMAT, DiagnosticTimelineBuilder::DISPLAY_TIMEZONE)
                    ->sortable(),
                TextColumn::make('last_event_at')
                    ->label('最後事件時間（裝置）')
                    ->dateTime(self::DATE_TIME_FORMAT, DiagnosticTimelineBuilder::DISPLAY_TIMEZONE)
                    ->sortable(),
                TextColumn::make('license.name')
                    ->label('授權名稱')
                    ->placeholder('（授權已刪除）')
                    ->url(fn (DiagnosticSession $record): ?string => static::licenseUrl($record))
                    ->searchable(),
                TextColumn::make('fingerprint')
                    ->label('裝置指紋')
                    ->fontFamily(FontFamily::Mono)
                    ->copyable()
                    ->searchable(),
                TextColumn::make('app_version')
                    ->label('App 版本'),
                TextColumn::make('app_env')
                    ->label('環境')
                    ->badge()
                    ->placeholder('—')
                    ->color(fn (?string $state): string => match ($state) {
                        'prod' => 'success',
                        'staging' => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('event_count')
                    ->label('事件數')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('error_count')
                    ->label('錯誤數')
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'danger' : 'gray')
                    ->sortable(),
                TextColumn::make('last_error_name')
                    ->label('最後錯誤')
                    ->fontFamily(FontFamily::Mono)
                    ->placeholder('—'),
                TextColumn::make('duration')
                    ->label('持續時間')
                    ->state(fn (DiagnosticSession $record): ?string => static::formatDuration($record))
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('license')
                    ->label('授權')
                    ->relationship('license', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('app_env')
                    ->label('環境')
                    ->options(self::ENVIRONMENTS),
                Filter::make('has_errors')
                    ->label('只看有錯誤的')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->where('error_count', '>', 0)),
                // 精確比對指紋：供「席位 → 此裝置的診斷紀錄」入口帶入。
                Filter::make('fingerprint')
                    ->schema([
                        TextInput::make('value')->label('裝置指紋'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        filled($data['value'] ?? null),
                        fn (Builder $query): Builder => $query->where('fingerprint', $data['value']),
                    ))
                    ->indicateUsing(fn (array $data): ?string => filled($data['value'] ?? null)
                        ? '裝置指紋：'.$data['value']
                        : null),
                Filter::make('last_event_at')
                    ->schema([
                        DatePicker::make('from')->label('最後事件時間（裝置，起）'),
                        DatePicker::make('until')->label('最後事件時間（裝置，迄）'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(
                            filled($data['from'] ?? null),
                            fn (Builder $query): Builder => $query->where('last_event_at', '>=', static::dayBoundary($data['from'])),
                        )
                        ->when(
                            filled($data['until'] ?? null),
                            fn (Builder $query): Builder => $query->where('last_event_at', '<', static::dayBoundary($data['until'])->addDay()),
                        ))
                    ->indicateUsing(function (array $data): array {
                        return array_values(array_filter([
                            filled($data['from'] ?? null) ? '自 '.Carbon::parse($data['from'])->format('Y-m-d') : null,
                            filled($data['until'] ?? null) ? '至 '.Carbon::parse($data['until'])->format('Y-m-d') : null,
                        ]));
                    }),
            ])
            ->recordActions([
                ViewAction::make()->label('時間軸'),
                DeleteAction::make(),
            ])
            // 裝置時鐘可能不準（甚至在未來），預設依伺服器收到的時間排序。
            ->defaultSort('last_received_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDiagnosticSessions::route('/'),
            'view' => ViewDiagnosticSession::route('/{record}'),
        ];
    }

    public static function formatDuration(DiagnosticSession $session): ?string
    {
        $milliseconds = $session->durationInMilliseconds();

        return $milliseconds === null
            ? null
            : app(DiagnosticTimelineBuilder::class)->formatGap(max(0, $milliseconds));
    }

    private static function licenseUrl(DiagnosticSession $session): ?string
    {
        return $session->license_id === null
            ? null
            : LicenseResource::getUrl('view', ['record' => $session->license_id]);
    }

    /**
     * 篩選的日期是管理者在台灣時區選的「某一天」；轉成 app timezone 的當日零點，
     * 才能與資料庫中的時間欄位比較。
     */
    private static function dayBoundary(string $date): Carbon
    {
        return Carbon::parse($date, DiagnosticTimelineBuilder::DISPLAY_TIMEZONE)
            ->startOfDay()
            ->setTimezone(config('app.timezone'));
    }
}
