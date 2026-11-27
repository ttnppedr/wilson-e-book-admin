<?php

namespace App\Console\Commands;

use App\Models\DiagnosticEvent;
use App\Models\DiagnosticSession;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

#[Signature('diagnostics:prune {--days=30 : 保留天數，最後事件早於此天數的 session 會被刪除}')]
#[Description('清除超過保留期的 App 診斷紀錄（session 連同事件）。')]
class DiagnosticsPruneCommand extends Command
{
    private const CHUNK_SIZE = 500;

    public function handle(): int
    {
        $days = filter_var($this->option('days'), FILTER_VALIDATE_INT);

        // 0 或負數會把全部（甚至未來時間的）紀錄刪光，一律拒絕。
        if ($days === false || $days < 1) {
            $this->error('--days 必須是大於 0 的整數。');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);
        $deletedSessions = 0;

        // 診斷紀錄不是 audit 對象，可以批次刪除。事件明確先刪，不依賴 DB 的
        // ON DELETE CASCADE（SQLite 需連線開啟 foreign key 才會生效）。
        DiagnosticSession::query()
            ->where('last_event_at', '<', $cutoff)
            ->select('id')
            ->chunkById(self::CHUNK_SIZE, function (Collection $sessions) use (&$deletedSessions): void {
                $ids = $sessions->modelKeys();

                DiagnosticEvent::query()->whereIn('diagnostic_session_id', $ids)->delete();
                $deletedSessions += DiagnosticSession::query()->whereIn('id', $ids)->delete();
            });

        // 零筆也記：否則「排程沒跑」與「跑了但沒東西可清」事後無從區分。
        Log::info('[diagnostics] prune', [
            'days' => $days,
            'deleted_sessions' => $deletedSessions,
        ]);

        $this->info("已刪除 {$deletedSessions} 個診斷 session（保留 {$days} 天）。");

        return self::SUCCESS;
    }
}
