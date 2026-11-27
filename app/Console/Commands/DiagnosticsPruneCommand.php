<?php

namespace App\Console\Commands;

use App\Models\DiagnosticEvent;
use App\Models\DiagnosticSession;
use Carbon\CarbonInterface;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

#[Signature('diagnostics:prune {--days= : 改以天數指定保留期（預設三個月），伺服器收到時間早於此的紀錄會被刪除}')]
#[Description('清除超過保留期的 App 診斷紀錄（事件，以及已無事件的 session）。')]
class DiagnosticsPruneCommand extends Command
{
    /** 預設保留期（月） */
    public const RETENTION_MONTHS = 3;

    private const CHUNK_SIZE = 500;

    private const DATE_FORMAT = 'Y-m-d H:i:s.v';

    public function handle(): int
    {
        $cutoff = $this->cutoff();

        if ($cutoff === null) {
            $this->error('--days 必須是大於 0 的整數。');

            return self::FAILURE;
        }

        // 一律以伺服器收到的時間判斷，不看裝置自報的時間：時鐘停在過去的裝置會讓
        // 紀錄隔天就被清掉，時鐘在未來的則永遠清不掉。
        //
        // 診斷紀錄不是 audit 對象，可以批次刪除。事件明確先刪，不依賴 DB 的
        // ON DELETE CASCADE（SQLite 需連線開啟 foreign key 才會生效）。
        $deletedSessions = $this->deleteExpiredSessions($cutoff);
        [$deletedEvents, $emptiedSessions] = $this->deleteExpiredEventsOfLiveSessions($cutoff);
        $deletedSessions += $emptiedSessions;

        // 零筆也記：否則「排程沒跑」與「跑了但沒東西可清」事後無從區分。
        Log::info('[diagnostics] prune', [
            'cutoff' => $cutoff->toIso8601String(),
            'deleted_sessions' => $deletedSessions,
            'deleted_events_of_live_sessions' => $deletedEvents,
        ]);

        $this->info("已刪除 {$deletedSessions} 個診斷 session，另從仍在保留期內的 session 刪除 {$deletedEvents} 筆過期事件。");

        return self::SUCCESS;
    }

    private function cutoff(): ?CarbonInterface
    {
        $option = $this->option('days');

        if ($option === null) {
            return now()->subMonthsNoOverflow(self::RETENTION_MONTHS);
        }

        $days = filter_var($option, FILTER_VALIDATE_INT);

        // 0 或負數會把全部（甚至未來時間的）紀錄刪光，一律拒絕。
        return $days === false || $days < 1 ? null : now()->subDays($days);
    }

    /**
     * 最後一次上傳就已超過保留期的 session：裡面每一筆事件都過期，整個刪掉。
     */
    private function deleteExpiredSessions(CarbonInterface $cutoff): int
    {
        $deleted = 0;

        DiagnosticSession::query()
            ->where('last_received_at', '<', $cutoff->format(self::DATE_FORMAT))
            ->select('id')
            ->chunkById(self::CHUNK_SIZE, function (Collection $sessions) use (&$deleted): void {
                $ids = $sessions->modelKeys();

                DiagnosticEvent::query()->whereIn('diagnostic_session_id', $ids)->delete();
                $deleted += DiagnosticSession::query()->whereIn('id', $ids)->delete();
            });

        return $deleted;
    }

    /**
     * 還在上傳的長 session（白板好幾個月沒關）：只刪其中過期的事件，並把 session
     * 的計數欄位改成剩下的事件數，清單上的數字才對得上時間軸。
     *
     * @return array{int, int} [刪除的事件數, 因此變空而一併刪除的 session 數]
     */
    private function deleteExpiredEventsOfLiveSessions(CarbonInterface $cutoff): array
    {
        $expired = fn () => DiagnosticEvent::query()
            ->where('received_at', '<', $cutoff->format(self::DATE_FORMAT));

        $sessionIds = $expired()->distinct()->pluck('diagnostic_session_id');
        $deletedEvents = 0;
        $emptiedSessions = 0;

        foreach ($sessionIds as $sessionId) {
            DB::transaction(function () use ($expired, $sessionId, &$deletedEvents, &$emptiedSessions): void {
                // 與上傳端鎖同一列，避免計數欄位和同時進來的上傳互相覆蓋。
                $session = DiagnosticSession::query()->lockForUpdate()->find($sessionId);

                $deletedEvents += $expired()->where('diagnostic_session_id', $sessionId)->delete();

                if ($session === null) {
                    return;
                }

                $remaining = DiagnosticEvent::query()->where('diagnostic_session_id', $sessionId);
                $count = (clone $remaining)->count();

                if ($count === 0) {
                    $session->delete();
                    $emptiedSessions++;

                    return;
                }

                $session->event_count = $count;
                $session->warn_count = (clone $remaining)->where('level', 'warn')->count();
                $session->error_count = (clone $remaining)->where('level', 'error')->count();
                $session->first_event_at = (clone $remaining)->min('occurred_at');
                $session->last_error_name = (clone $remaining)
                    ->where('level', 'error')
                    ->orderByDesc('seq')
                    ->value('name');
                $session->save();
            });
        }

        return [$deletedEvents, $emptiedSessions];
    }
}
