<?php

namespace App\Services;

use App\Models\DiagnosticEvent;
use App\Models\DiagnosticSession;
use Carbon\CarbonInterface;

/**
 * 把一個診斷 session 的事件整理成時間軸檢視所需的資料。
 *
 * 事件依 `seq` 排序而非 `occurred_at`：seq 由 App 遞增給號、一定反映實際先後；
 * occurred_at 是裝置時間，可能不準或中途被校時。
 */
class DiagnosticTimelineBuilder
{
    /** 後台顯示時區 */
    public const DISPLAY_TIMEZONE = 'Asia/Taipei';

    /** 每次載入的事件筆數 */
    public const PAGE_SIZE = 2000;

    /** 相鄰事件間隔達到此秒數時，在兩筆之間插入分隔線 */
    private const GAP_DIVIDER_SECONDS = 60;

    /** 間隔達到此秒數（但未達分隔線門檻）時，把該列的間隔加粗，讓停頓看得出來 */
    private const LONG_DELTA_SECONDS = 5;

    /** 每列直接顯示的 data 重點最多幾組 key=value */
    private const SUMMARY_MAX_PAIRS = 4;

    private const SUMMARY_MAX_VALUE_LENGTH = 80;

    /** App 轉送內部 log 時使用的固定事件名稱（訊息在 `data.msg`） */
    private const TRACE_EVENT = 'trace';

    /** 以訊息當標題時的長度上限 */
    private const TITLE_MAX_LENGTH = 160;

    /**
     * 已知類別的中文標籤，順序即類別篩選列的順序。類別由 App 自報、後端不設
     * 白名單，未知類別直接顯示原名。
     *
     * `tap` 涵蓋所有觸控輸入（事件名稱 `tap`／`drag`／`hold`／`activate`），
     * 所以標成「觸控」而非「點擊」。
     *
     * @var array<string, string>
     */
    public const CATEGORY_LABELS = [
        'app' => '應用程式',
        'nav' => '頁面',
        'tap' => '觸控',
        'ui' => '介面狀態',
        'auth' => '授權',
        'content' => '教材來源',
        'media' => '音訊影片',
        'game' => '遊戲',
        'net' => '網路',
        'log' => '內部紀錄',
        'error' => '錯誤',
    ];

    /**
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     categories: list<array{key: string, label: string, count: int, known: bool}>,
     *     loadedCount: int,
     *     totalCount: int,
     *     earlierCount: int,
     *     issueCount: int,
     *     hasLoadedError: bool,
     * }
     */
    public function build(DiagnosticSession $session, int $limit): array
    {
        $limit = max(1, $limit);

        // 取最新的 $limit 筆（問題通常出在 session 尾端），多取一筆只為了算出
        // 畫面上第一筆與前一筆的間隔。
        $events = DiagnosticEvent::query()
            ->where('diagnostic_session_id', $session->getKey())
            ->orderByDesc('seq')
            ->limit($limit + 1)
            ->get()
            ->reverse()
            ->values();

        $previous = $events->count() > $limit ? $events->shift() : null;
        $totalCount = DiagnosticEvent::query()
            ->where('diagnostic_session_id', $session->getKey())
            ->count();

        $rows = [];
        $categoryCounts = [];
        $issueCount = 0;
        $hasLoadedError = false;
        $previousDate = null;

        foreach ($events as $event) {
            $occurredAt = $event->occurred_at->copy()->setTimezone(self::DISPLAY_TIMEZONE);
            $deltaMs = $previous === null
                ? null
                : (int) round($previous->occurred_at->diffInMilliseconds($event->occurred_at));

            $date = $occurredAt->format('Y-m-d');
            if ($date !== $previousDate) {
                $rows[] = ['type' => 'day', 'label' => $this->formatDay($occurredAt)];
                $previousDate = $date;
            }

            if ($deltaMs !== null && abs($deltaMs) >= self::GAP_DIVIDER_SECONDS * 1000) {
                $rows[] = [
                    'type' => 'gap',
                    'label' => $deltaMs > 0
                        ? '間隔 '.$this->formatGap($deltaMs)
                        : '裝置時間往回跳 '.$this->formatGap(abs($deltaMs)),
                ];
            }

            $category = $this->normalizeCategory($event->category);
            $categoryCounts[$category] = ($categoryCounts[$category] ?? 0) + 1;

            $level = in_array($event->level, ['info', 'warn', 'error'], true) ? $event->level : 'info';
            $issueCount += $level === 'info' ? 0 : 1;
            $hasLoadedError = $hasLoadedError || $level === 'error';

            $rows[] = [
                'type' => 'event',
                'seq' => $event->seq,
                'time' => $occurredAt->format('H:i:s.v'),
                'receivedAt' => $event->received_at->copy()->setTimezone(self::DISPLAY_TIMEZONE)->format('Y-m-d H:i:s.v'),
                'delta' => $deltaMs === null ? null : $this->formatDelta($deltaMs),
                'deltaIsLong' => $deltaMs !== null && abs($deltaMs) >= self::LONG_DELTA_SECONDS * 1000,
                'category' => $category,
                'categoryLabel' => self::CATEGORY_LABELS[$category] ?? $category,
                'categoryKnown' => isset(self::CATEGORY_LABELS[$category]),
                'name' => $this->title($event->name, $event->data),
                'level' => $level,
                'summary' => $this->summarize($this->withoutTitleField($event->name, $event->data)),
                'json' => $this->prettyJson($event->data),
            ];

            $previous = $event;
        }

        return [
            'rows' => $rows,
            'categories' => $this->buildCategories($categoryCounts),
            'loadedCount' => $events->count(),
            'totalCount' => $totalCount,
            'earlierCount' => max(0, $totalCount - $events->count()),
            'issueCount' => $issueCount,
            'hasLoadedError' => $hasLoadedError,
        ];
    }

    /**
     * 相鄰事件間隔的精簡寫法：`+120ms`、`+2.3s`、`+4m12s`、`+1h02m`。
     */
    public function formatDelta(int $milliseconds): string
    {
        $sign = $milliseconds < 0 ? '−' : '+';
        $ms = abs($milliseconds);

        if ($ms < 1000) {
            return "{$sign}{$ms}ms";
        }

        if ($ms < 60_000) {
            return $sign.number_format($ms / 1000, 1).'s';
        }

        $seconds = intdiv($ms, 1000);

        if ($seconds < 3600) {
            return sprintf('%s%dm%02ds', $sign, intdiv($seconds, 60), $seconds % 60);
        }

        return sprintf('%s%dh%02dm', $sign, intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
    }

    /**
     * 一段時間長度的中文寫法：`850 毫秒`、`12.3 秒`、`4 分 12 秒`、`1 小時 2 分`。
     */
    public function formatGap(int $milliseconds): string
    {
        if ($milliseconds < 1000) {
            return "{$milliseconds} 毫秒";
        }

        if ($milliseconds < 60_000) {
            return rtrim(rtrim(number_format($milliseconds / 1000, 1), '0'), '.').' 秒';
        }

        $seconds = intdiv($milliseconds, 1000);

        if ($seconds < 3600) {
            return sprintf('%d 分 %d 秒', intdiv($seconds, 60), $seconds % 60);
        }

        if ($seconds < 86_400) {
            return sprintf('%d 小時 %d 分', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
        }

        return sprintf('%d 天 %d 小時', intdiv($seconds, 86_400), intdiv($seconds % 86_400, 3600));
    }

    /**
     * 類別會被拿去組 CSS class 與 selector。寫入時已驗證過格式，這裡再擋一次，
     * 不讓檢視層的安全性依賴「資料一定是從 API 進來的」。
     */
    private function normalizeCategory(string $category): string
    {
        return preg_match('/^[a-z_]{1,32}$/', $category) === 1 ? $category : 'other';
    }

    private function formatDay(CarbonInterface $date): string
    {
        $weekday = ['日', '一', '二', '三', '四', '五', '六'][$date->dayOfWeek];

        return $date->format('Y-m-d')."（{$weekday}）";
    }

    /**
     * 已知類別依固定順序排在前面，未知類別依名稱排在後面。
     *
     * @param  array<string, int>  $counts
     * @return list<array{key: string, label: string, count: int, known: bool}>
     */
    private function buildCategories(array $counts): array
    {
        $known = array_intersect_key(self::CATEGORY_LABELS, $counts);
        $unknown = array_diff_key($counts, self::CATEGORY_LABELS);
        ksort($unknown);

        $categories = [];

        foreach (array_keys($known) as $key) {
            $categories[] = ['key' => $key, 'label' => self::CATEGORY_LABELS[$key], 'count' => $counts[$key], 'known' => true];
        }

        foreach ($unknown as $key => $count) {
            $categories[] = ['key' => $key, 'label' => $key, 'count' => $count, 'known' => false];
        }

        return $categories;
    }

    /**
     * App 既有的內部 log 以 `trace` 這個固定名稱上傳，真正的內容在 `data.msg`。
     * 照原樣顯示會是一整排「trace」，所以這類事件改以訊息本身當標題。
     *
     * @param  array<array-key, mixed>|null  $data
     */
    private function title(string $name, ?array $data): string
    {
        if ($name === self::TRACE_EVENT && is_string($data['msg'] ?? null) && $data['msg'] !== '') {
            return mb_strimwidth($data['msg'], 0, self::TITLE_MAX_LENGTH, '…');
        }

        return $name;
    }

    /**
     * 訊息已經當成標題顯示，摘要裡不再重複一次。
     *
     * @param  array<array-key, mixed>|null  $data
     * @return array<array-key, mixed>|null
     */
    private function withoutTitleField(string $name, ?array $data): ?array
    {
        if ($data === null || $this->title($name, $data) === $name) {
            return $data;
        }

        unset($data['msg']);

        return $data;
    }

    /**
     * 把一層巢狀物件攤平成 `error.kind` 這種鍵。例外資訊固定放在 `error` 底下，
     * 不攤平的話摘要只會顯示 `error={…}`，最需要看的內容反而要展開才看得到。
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private function flatten(array $data): array
    {
        $flat = [];

        foreach ($data as $key => $value) {
            if (is_array($value) && $value !== [] && ! array_is_list($value)) {
                foreach ($value as $childKey => $childValue) {
                    $flat[$key.'.'.$childKey] = $childValue;
                }

                continue;
            }

            $flat[$key] = $value;
        }

        return $flat;
    }

    /**
     * 取 data 的前幾個欄位做成 key=value，讓人不必展開就看得出這筆事件在講什麼。
     *
     * @param  array<array-key, mixed>|null  $data
     * @return list<array{key: string, value: string}>
     */
    private function summarize(?array $data): array
    {
        if ($data === null) {
            return [];
        }

        $data = $this->flatten($data);
        $pairs = [];

        foreach (array_slice($data, 0, self::SUMMARY_MAX_PAIRS, true) as $key => $value) {
            $pairs[] = [
                'key' => mb_strimwidth((string) $key, 0, 32, '…'),
                'value' => match (true) {
                    is_bool($value) => $value ? 'true' : 'false',
                    $value === null => 'null',
                    is_array($value) => array_is_list($value) ? '['.count($value).' 項]' : '{…}',
                    default => mb_strimwidth((string) $value, 0, self::SUMMARY_MAX_VALUE_LENGTH, '…'),
                },
            ];
        }

        if (count($data) > self::SUMMARY_MAX_PAIRS) {
            $pairs[] = ['key' => '…', 'value' => '另有 '.(count($data) - self::SUMMARY_MAX_PAIRS).' 個欄位'];
        }

        return $pairs;
    }

    /**
     * @param  array<array-key, mixed>|null  $data
     */
    private function prettyJson(?array $data): ?string
    {
        if ($data === null) {
            return null;
        }

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json === false ? null : $json;
    }
}
