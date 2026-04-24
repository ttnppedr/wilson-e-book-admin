<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * 授權排程：每日把 expires_at 已過的 License 由 active 推入 grace，
 * 再把 grace 期滿的推入 expired。執行時間由 config/licensing.php 的
 * scheduler.check_expirations 控制，關閉時整段跳過不註冊。
 *
 * onOneServer() 確保多台 worker 時只有一台執行，withoutOverlapping()
 * 避免前次執行尚未結束時重複啟動。
 */
if (config('licensing.scheduler.check_expirations.enabled', true)) {
    Schedule::command('licensing:check-expirations')
        ->dailyAt(config('licensing.scheduler.check_expirations.time', '02:00'))
        ->timezone(config('app.timezone'))
        ->onOneServer()
        ->withoutOverlapping();
}
