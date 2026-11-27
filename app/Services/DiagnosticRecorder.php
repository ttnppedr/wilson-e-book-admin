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
 *   1. 指紋必須對得上既有的 LicenseUsage，否則整批丟棄（由呼叫端先以
 *      `findUsage()` 確認，對不上就不必驗證、也不必處理整批事件）。
 *   2. session 一旦建立就綁定建立時的指紋，別的指紋不能往裡面追加事件。
 *   3. 單筆 data 超過上限就換成 `{"_truncated": true}`。
 */
class DiagnosticRecorder
{
    /** 單筆事件 data 編碼成 JSON 後的位元組上限 */
    public const MAX_DATA_BYTES = 4096;

    private const DATE_FORMAT = 'Y-m-d H:i:s.v';

    private const TRUNCATED_DATA = '{"_truncated":true}';

    /**
     * @param  array{
     *     fingerprint: string,
     *     session_id: string,
     *     started_at: string,
     *     app: array{version: string, env?: string|null, platform: string, os_version?: string|null, model?: string|null},
     *     events: list<array{seq: int|string, t: string, cat: string, name: string, level: string, data?: array<array-key, mixed>|null}>
     * }  $payload  已通過驗證的請求內容
     * @param  LicenseUsage  $usage  `findUsage()` 以該指紋找到的席位
     */
    public function record(array $payload, LicenseUsage $usage, ?string $ip): DiagnosticUploadResult
    {
        $receivedAt = now()->format(self::DATE_FORMAT);
        $rows = $this->buildEventRows($payload['events'], $receivedAt);

        return DB::transaction(function () use ($payload, $usage, $rows, $receivedAt, $ip): DiagnosticUploadResult {
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
                    'last_received_at' => $receivedAt,
                    'ip' => $ip,
                ],
            );

            // 鎖住 session 列：同一 session 的並行上傳（App 重試與原請求重疊）必須
            // 依序處理，否則兩邊都會把同一批事件算進計數欄位。
            $session = DiagnosticSession::query()->lockForUpdate()->findOrFail($session->getKey());

            $context = [
                'diagnostic_session_id' => $session->getKey(),
                'license_id' => $session->license_id,
                'license_usage_id' => $session->license_usage_id,
                'received' => count($payload['events']),
                'seq_min' => min(array_keys($rows)),
                'seq_max' => max(array_keys($rows)),
            ];

            if ($session->fingerprint !== $payload['fingerprint']) {
                return new DiagnosticUploadResult(DiagnosticUploadResult::OUTCOME_FINGERPRINT_MISMATCH, 0, [
                    ...$context,
                    'bound_fingerprint_prefix' => substr($session->fingerprint, 0, 8),
                ]);
            }

            $existingSeqs = DiagnosticEvent::query()
                ->where('diagnostic_session_id', $session->getKey())
                ->whereIn('seq', array_keys($rows))
                ->pluck('seq')
                ->all();

            $newRows = array_diff_key($rows, array_flip($existingSeqs));
            $context['duplicates'] = count($payload['events']) - count($newRows);

            if ($newRows === []) {
                return new DiagnosticUploadResult(DiagnosticUploadResult::OUTCOME_DUPLICATE_ONLY, 0, $context);
            }

            $accepted = DiagnosticEvent::query()->insertOrIgnore(array_map(
                fn (array $row): array => ['diagnostic_session_id' => $session->getKey(), ...$row],
                array_values($newRows),
            ));

            $this->updateSessionSummary($session, $newRows, $receivedAt, $ip);

            return new DiagnosticUploadResult(DiagnosticUploadResult::OUTCOME_STORED, $accepted, [
                ...$context,
                'truncated' => count(array_filter($newRows, fn (array $row): bool => $row['data'] === self::TRUNCATED_DATA)),
            ]);
        });
    }

    /**
     * 同一指紋可能掛在多張授權下（裝置重新啟用過別張授權），取最近有連線的那筆。
     */
    public function findUsage(string $fingerprint): ?LicenseUsage
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
    private function buildEventRows(array $events, string $receivedAt): array
    {
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
            return self::TRUNCATED_DATA;
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
    private function updateSessionSummary(DiagnosticSession $session, array $newRows, string $receivedAt, ?string $ip): void
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
        $session->last_received_at = $receivedAt;
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
