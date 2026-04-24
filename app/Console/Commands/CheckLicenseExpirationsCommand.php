<?php

namespace App\Console\Commands;

use App\Models\License;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use LucaLongo\Licensing\Enums\LicenseStatus;

#[Signature('licensing:check-expirations')]
#[Description('把 expires_at 已過的 active License 轉為 grace；grace 期滿再轉為 expired。')]
class CheckLicenseExpirationsCommand extends Command
{
    public function handle(): int
    {
        $movedToGrace = 0;
        $movedToExpired = 0;

        License::query()
            ->where('status', LicenseStatus::Active)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->cursor()
            ->each(function (License $license) use (&$movedToGrace): void {
                $license->transitionToGrace();

                if ($license->wasChanged('status')) {
                    $movedToGrace++;
                }
            });

        License::query()
            ->where('status', LicenseStatus::Grace)
            ->cursor()
            ->each(function (License $license) use (&$movedToExpired): void {
                $license->transitionToExpired();

                if ($license->wasChanged('status')) {
                    $movedToExpired++;
                }
            });

        $this->info("轉入 grace: {$movedToGrace}；轉入 expired: {$movedToExpired}");

        return self::SUCCESS;
    }
}
