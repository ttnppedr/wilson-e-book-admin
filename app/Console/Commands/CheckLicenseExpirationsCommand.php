<?php

namespace App\Console\Commands;

use App\Models\License;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('licensing:check-expirations')]
#[Description('把 expires_at 已過的 License 轉為 expired（本專案無寬限期）。')]
class CheckLicenseExpirationsCommand extends Command
{
    /**
     * 產品決策：**無寬限期，到期即失效**。原本是 active → grace →（14 天後）
     * expired 兩段式，現在一步到位。
     *
     * 注意：API 的到期判斷**不依賴這支指令**——guardLicenseState() 直接看
     * `expires_at`（`License::isExpired()` 是純日期判斷），授權在到期當下就會
     * 被拒絕，不必等隔天 02:00 的排程。這支的作用是讓 DB 的 status 欄位與事實
     * 一致（後台顯示、統計、LicenseExpired 事件）。
     */
    public function handle(): int
    {
        $movedToExpired = 0;

        License::query()
            // 與 License::transitionToExpired() 的 guard 同源，避免兩處分歧。
            ->whereIn('status', License::EXPIRABLE_STATUSES)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->cursor()
            ->each(function (License $license) use (&$movedToExpired): void {
                $license->transitionToExpired();

                if ($license->wasChanged('status')) {
                    $movedToExpired++;
                }
            });

        $this->info("轉入 expired: {$movedToExpired}");

        return self::SUCCESS;
    }
}
