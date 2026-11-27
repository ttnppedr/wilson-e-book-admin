<?php

namespace App\Services;

use App\Models\DiagnosticEvent;
use App\Models\DiagnosticSession;
use App\Models\LicenseUsage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 把 App 上傳的一批診斷事件寫入資料庫。
 *
 * 上傳端點免驗證，所以這裡有三道限制，缺一就會變成任意寫入口：
 *   1. 指紋必須對得上既有的 LicenseUsage，否則整批丟棄。
 *   2. session 一旦建立就綁定建立時的指紋，別的指紋不能往裡面追加事件。
 *   3. 單筆 data 超過上限就換成 `{"_truncated": true}`。
 */
class DiagnosticRecorder
{
    /** 單筆事件 data 編碼成 JSON 後的位元組上限 */
    public const MAX_DATA_BYTES = 4096;

    private const DATE_FORMAT = 'Y-m-d H:i:s.v';

    /**
     * @param  array{
     *     fingerprint: string,
     *     session_id: string,
     *     started_at: string,
     *     app: array{version: string, env?: string|null, platform: string, os_version?: string|null, model?: string|null},
     *     events: list<array{seq: int|string, t: string, cat: string, name: string, level: string, data?: array<array-key, mixed>|null}>
     * }  $payload  已通過驗證的請求內容
     * @return int 實際新寫入的事件筆數
     */
    public function record(array $payload, ?string $ip): int
    {
        $usage = $this->findUsage($payload['fingerprint']);

        if ($usage === null) {
            return 0;
        }

        $rows = $this->buildEventRows($payload['events']);

        return DB::transaction(function () use ($payload, $usage, $rows, $ip): int {
            $session = DiagnosticSession::query()->firstOrCreate(
                ['session_uuid' => $payload['session_id']],
                [
                    'fingerprint' => $payload['fingerprint'],
                    'license_id' => $usage->license_id,
                    'license_usage_id' => $usage->getKey(),
                    'app_version' => $payload['app']['version'],
                    'app_env' => $payload['app']['env'] ?? null,
                    'platform' => $payload['app']['platform'],
                    'os_version' => $payload['app']['os_version'] ?? null,
                    'device_model' => $payload['app']['model'] ?? null,
                    'started_at' => $this->toStoredTime($payload['started_at']),
                    'ip' => $ip,
                ],
            );

            // 鎖住 session 列：同一 session 的並行上傳（App 重試與原請求重疊）必須
            // 依序處理，否則兩邊都會把同一批事件算進計數欄位。
            $session = DiagnosticSession::query()->lockForUpdate()->findOrFail($session->getKey());

            if ($session->fingerprint !== $payload['fingerprint']) {
                return 0;
            }

            $existingSeqs = DiagnosticEvent::query()
                ->where('diagnostic_session_id', $session->getKey())
                ->whereIn('seq', array_keys($rows))
                ->pluck('seq')
                ->all();

            $newRows = array_diff_key($rows, array_flip($existingSeqs));

            if ($newRows === []) {
                return 0;
            }

            $accepted = DiagnosticEvent::query()->insertOrIgnore(array_map(
                fn (array $row): array => ['diagnostic_session_id' => $session->getKey(), ...$row],
                array_values($newRows),
            ));

            $this->updateSessionSummary($session, $newRows, $ip);

            return $accepted;
        });
    }

    /**
     * 同一指紋可能掛在多張授權下（裝置重新啟用過別張授權），取最近有連線的那筆。
     */
    private function findUsage(string $fingerprint): ?LicenseUsage
    {
        return LicenseUsage::query()
            ->where('usage_fingerprint', $fingerprint)
            ->orderByDesc('last_seen_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * 轉成可直接批次寫入的列，以 seq 為鍵（同一批內重複的 seq 只留第一筆）。
     *
     * @param  list<array{seq: int|string, t: string, cat: string, name: string, level: string, data?: array<array-key, mixed>|null}>  $events
     * @return array<int, array{seq: int, occurred_at: string, category: string, name: string, level: string, data: string|null, received_at: string}>
     */
    private function buildEventRows(array $events): array
    {
        $receivedAt = now()->format(self::DATE_FORMAT);
        $rows = [];

        foreach ($events as $event) {
            $seq = (int) $event['seq'];

            $rows[$seq] ??= [
                'seq' => $seq,
                'occurred_at' => $this->toStoredTime($event['t']),
                'category' => $event['cat'],
                'name' => $event['name'],
                'level' => $event['level'],
                'data' => $this->encodeData($event['data'] ?? null),
                'received_at' => $receivedAt,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<array-key, mixed>|null  $data
     */
    private function encodeData(?array $data): ?string
    {
        if ($data === null || $data === []) {
            return null;
        }

        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false || strlen($json) > self::MAX_DATA_BYTES) {
            return '{"_truncated":true}';
        }

        return $json;
    }

    /**
     * App 送的是 UTC；Eloquent 讀寫時間一律以 app timezone 解讀，所以先轉過去再存，
     * 與專案其他表的時間欄位語意一致。
     */
    private function toStoredTime(string $iso8601): string
    {
        return Carbon::parse($iso8601)
            ->setTimezone(config('app.timezone'))
            ->format(self::DATE_FORMAT);
    }

    /**
     * 只依「這次真的新寫入」的事件更新計數，重送的批次不會重複累加。
     *
     * @param  array<int, array{seq: int, occurred_at: string, category: string, name: string, level: string, data: string|null, received_at: string}>  $newRows
     */
    private function updateSessionSummary(DiagnosticSession $session, array $newRows, ?string $ip): void
    {
        $levels = array_count_values(array_column($newRows, 'level'));
        $occurredAt = array_column($newRows, 'occurred_at');

        // 固定寬度的 `Y-m-d H:i:s.v` 字串排序即時間排序。
        $earliest = min($occurredAt);
        $latest = max($occurredAt);
        $currentFirst = $session->first_event_at?->format(self::DATE_FORMAT);
        $currentLast = $session->last_event_at?->format(self::DATE_FORMAT);

        $session->event_count += count($newRows);
        $session->warn_count += $levels['warn'] ?? 0;
        $session->error_count += $levels['error'] ?? 0;
        $session->first_event_at = $currentFirst === null ? $earliest : min($currentFirst, $earliest);
        $session->last_event_at = $currentLast === null ? $latest : max($currentLast, $latest);
        $session->ip = $ip;

        if (isset($levels['error'])) {
            // 批次可能不照順序抵達（先前失敗的舊批次晚到），所以重新查而不是直接
            // 取這一批的最後一筆。
            $session->last_error_name = DiagnosticEvent::query()
                ->where('diagnostic_session_id', $session->getKey())
                ->where('level', 'error')
                ->orderByDesc('seq')
                ->value('name');
        }

        $session->save();
    }
}
