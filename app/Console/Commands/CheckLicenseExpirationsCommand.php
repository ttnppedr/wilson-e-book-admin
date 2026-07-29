<?php

namespace App\Console\Commands;

use App\Models\License;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('licensing:check-expirations')]
#[Description('把 expires_at 已過的 License 轉為 expired（本專案無寬限期）。')]
class CheckLicenseExpirationsCommand extends Command
{
    /**
     * 每一行 log 最多列出幾個 license id。
     *
     * **這不是上限，是分行寬度**——被變更的 id 一個都不能漏，所以逾量時是換下
     * 一行繼續寫，不是截斷。首次上線或長期未跑時可能一次收斂大量舊資料，把上萬
     * 個 id 塞進單一 log 行會被 log 管線切斷，那同樣會漏 id，只是換個地方漏。
     *
     * 常態下每天只有零星幾筆到期，只會產生一行，與不分批時完全相同。
     */
    private const LOGGED_IDS_PER_LINE = 200;

    /**
     * 產品決策：**無寬限期，到期即失效**。原本是 active → grace →（14 天後）
     * expired 兩段式，現在一步到位。
     *
     * 注意：API 的到期判斷**不依賴這支指令**——`guardLicenseState()` 與
     * `EnsureLicenseUsable` 都直接看 `expires_at`（`License::isExpired()` 是純
     * 日期判斷），授權在到期當下就會被拒絕，不必等隔天 02:00 的排程。
     *
     * 那兩處在判斷後也會自行呼叫 `transitionToExpired()` 收斂 status，所以有在
     * 使用的授權多半在排程跑到之前就已經是 expired 了。這支的作用因此收斂為
     * **兜底**：處理那些過期後根本沒有人再連線、永遠不會被 API 路徑碰到的授權，
     * 讓後台顯示與統計不會停在過時的 active／pending。
     */
    public function handle(): int
    {
        $movedToExpired = 0;
        $expiredLicenseIds = [];

        try {
            License::query()
                // 與 License::transitionToExpired() 的 guard 同源，避免兩處分歧。
                ->whereIn('status', License::EXPIRABLE_STATUSES)
                ->whereNotNull('expires_at')
                ->where('expires_at', '<', now())
                ->cursor()
                ->each(function (License $license) use (&$movedToExpired, &$expiredLicenseIds): void {
                    $license->transitionToExpired();

                    if ($license->wasChanged('status')) {
                        $movedToExpired++;
                        $expiredLicenseIds[] = $license->getKey();
                    }
                });
        } finally {
            // **不論有無異動都記一筆**：這行是「排程當天確實啟動過」的唯一正面
            // 證據。只在有異動時才記的話，「排程沒跑」與「排程跑了但沒東西到期」
            // 在事後完全無法區分，兩者都是零筆記錄。
            //
            // 放在 finally：迴圈中途拋錯時仍留下記錄（帶已處理的筆數），否則
            // 一次失敗執行看起來會和從未啟動一模一樣。
            Log::info('[licensing] check-expirations', [
                'moved_to_expired' => $movedToExpired,
            ]);

            // 被變更的 id 逐批列出，**一個都不省略**。零異動時不會有這幾行，
            // 上面的總結行仍在，「有執行」的證據不受影響。
            foreach (array_chunk($expiredLicenseIds, self::LOGGED_IDS_PER_LINE) as $batch => $ids) {
                Log::info('[licensing] check-expirations ids', [
                    'batch' => $batch + 1,
                    'license_ids' => $ids,
                ]);
            }
        }

        $this->info("轉入 expired: {$movedToExpired}");

        return self::SUCCESS;
    }
}
