<?php

namespace Database\Seeders;

use App\Filament\Resources\DiagnosticSessions\DiagnosticSessionResource;
use App\Models\DiagnosticSession;
use App\Models\License;
use App\Models\LicenseUsage;
use App\Services\DiagnosticRecorder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use LucaLongo\Licensing\Enums\LicenseStatus;
use LucaLongo\Licensing\Enums\UsageStatus;

/**
 * **僅供本機**：產生一個擬真的診斷 session，方便在後台檢查時間軸畫面。
 *
 *     php artisan db:seed --class=DiagnosticDemoSeeder
 *
 * 劇情：App 啟動 → USB 偵測成功 → 授權恢復 → 瀏覽與點擊 →（閒置約 4 分鐘）→
 * 開啟授權碼畫面 → 送出 → 教材載入失敗 ×3。
 *
 * 事件經由 DiagnosticRecorder 寫入，與真實上傳走同一條路徑。每次執行都會新增
 * 一個 session。
 */
class DiagnosticDemoSeeder extends Seeder
{
    public function run(DiagnosticRecorder $recorder): void
    {
        if (! app()->environment('local')) {
            $this->command?->warn('DiagnosticDemoSeeder：非 local 環境，已略過。');

            return;
        }

        $usage = $this->resolveUsage();
        $sessionId = bin2hex(random_bytes(16));
        $startedAt = now()->subMinutes(12)->utc();

        $accepted = $recorder->record([
            'fingerprint' => $usage->usage_fingerprint,
            'session_id' => $sessionId,
            'started_at' => $this->iso($startedAt),
            'app' => [
                'version' => '1.1.4+10',
                'env' => 'staging',
                'platform' => 'android',
                'os_version' => '13',
                'model' => 'MeetBoard_4',
            ],
            'events' => $this->buildEvents($startedAt),
        ], $usage, '127.0.0.1')->accepted;

        $session = DiagnosticSession::query()->where('session_uuid', $sessionId)->sole();

        $this->command?->info("已建立示範診斷 session（{$accepted} 筆事件），連到席位指紋 {$usage->usage_fingerprint}。");
        $this->command?->line(DiagnosticSessionResource::getUrl('view', ['record' => $session], panel: 'admin'));
    }

    /**
     * 優先連到本機 DB 既有的席位；一筆都沒有才建立測試用的授權與席位。
     */
    private function resolveUsage(): LicenseUsage
    {
        $usage = LicenseUsage::query()->orderByDesc('last_seen_at')->orderByDesc('id')->first();

        if ($usage !== null) {
            return $usage;
        }

        $license = License::create([
            'key_hash' => License::hashKey('DEMO'.bin2hex(random_bytes(8))),
            'status' => LicenseStatus::Active,
            'name' => '診斷示範授權',
            'activated_at' => now(),
            'expires_at' => now()->addYear(),
            'max_usages' => 1,
            'meta' => [],
        ]);

        return LicenseUsage::create([
            'license_id' => $license->getKey(),
            'usage_fingerprint' => '9312d107016e2503',
            'status' => UsageStatus::Active,
            'registered_at' => now(),
            'last_seen_at' => now(),
            'client_type' => 'android',
            'meta' => [],
        ]);
    }

    /**
     * @return list<array{seq: int, t: string, cat: string, name: string, level: string, data?: array<string, mixed>}>
     */
    private function buildEvents(Carbon $startedAt): array
    {
        /** @var list<array{0: int, 1: string, 2: string, 3?: array<string, mixed>, 4?: string}> $script 與上一筆的間隔毫秒、類別、名稱、data、level */
        $script = [
            // App 啟動
            [0, 'app', 'start', ['version' => '1.1.4+10', 'env' => 'staging', 'cold_start' => true]],
            [84, 'app', 'engine_ready', ['ms' => 84]],
            [212, 'app', 'device_info', ['model' => 'MeetBoard_4', 'os' => '13', 'screen' => '3840x2160', 'locale' => 'zh_TW']],
            // USB 偵測
            [140, 'content', 'usb_scan_start', ['root' => 'wilson_content_staging']],
            [1630, 'content', 'usb_detected', ['volume' => 'A1B2-C3D4', 'path' => '/storage/A1B2-C3D4/wilson_content_staging']],
            [310, 'content', 'manifest_loaded', ['env' => 'staging', 'files' => 18422, 'bytes' => 6812345678, 'format' => 'WEBK v3']],
            [45, 'content', 'source_ready', ['source' => 'usb']],
            // 授權恢復
            [20, 'auth', 'state', ['from' => 'unknown', 'to' => 'restoring']],
            [35, 'auth', 'restore_start', ['has_token' => true, 'has_content_key' => true]],
            [12, 'net', 'request', ['method' => 'POST', 'path' => '/api/licensing/v1/validate']],
            [486, 'net', 'response', ['path' => '/api/licensing/v1/validate', 'status' => 200, 'ms' => 486]],
            [18, 'auth', 'restore_ok', ['license_id' => 12, 'expires_in_days' => 214]],
            [6, 'auth', 'state', ['from' => 'restoring', 'to' => 'authenticated']],
            // 首頁
            [130, 'nav', 'push', ['route' => 'home']],
            [2140, 'tap', 'home_category', ['category' => 'speedUp', 'x' => 812, 'y' => 1040]],
            [160, 'nav', 'push', ['route' => 'speed_up/books']],
            [1850, 'tap', 'book_cover', ['book' => 4, 'x' => 1920, 'y' => 980]],
            [140, 'nav', 'push', ['route' => 'speed_up/book4/units']],
            [1320, 'tap', 'unit_tile', ['book' => 4, 'unit' => 2]],
            [170, 'nav', 'push', ['route' => 'speed_up/book4/unit2/section1', 'section' => 'conversation']],
            [240, 'content', 'asset_open', ['asset' => 'speed-up-4/unit2/conversation/bg.png', 'ms' => 38]],
            [900, 'tap', 'play_audio', ['line' => 'scene01_sam']],
            [42, 'content', 'asset_open', ['asset' => 'speed-up-4/unit2/conversation/scene01_sam.wav', 'ms' => 21]],
            [3600, 'tap', 'play_audio', ['line' => 'scene01_amy']],
            [3900, 'tap', 'toolbar_next', ['x' => 3720, 'y' => 2080]],
            [150, 'nav', 'replace', ['route' => 'speed_up/book4/unit2/section2', 'section' => 'vocabulary']],
            [1100, 'tap', 'word_card', ['word' => 'january']],
            [820, 'tap', 'word_card', ['word' => 'february']],
            [760, 'tap', 'word_card', ['word' => 'march']],
            [2300, 'tap', 'toolbar_pen', ['enabled' => true]],
            [5400, 'tap', 'toolbar_pen', ['enabled' => false]],
            [900, 'tap', 'toolbar_next', ['x' => 3720, 'y' => 2080]],
            [160, 'nav', 'replace', ['route' => 'speed_up/book4/unit2/section3', 'section' => 'practice']],
            [310, 'content', 'asset_slow', ['asset' => 'speed-up-4/unit2/practice/page1.png', 'ms' => 1840, 'threshold_ms' => 800], 'warn'],
            [2100, 'tap', 'hint_reveal', ['index' => 1]],
            [1700, 'tap', 'hint_reveal', ['index' => 2]],
            [2600, 'tap', 'toolbar_home', []],
            [140, 'nav', 'pop_until', ['route' => 'home']],
            [3200, 'tap', 'home_category', ['category' => 'game']],
            [150, 'nav', 'push', ['route' => 'game/list']],
            [11, 'net', 'request', ['method' => 'GET', 'path' => '/api/v1/wordwall-categories']],
            [392, 'net', 'response', ['path' => '/api/v1/wordwall-categories', 'status' => 304, 'ms' => 392]],
            [2800, 'tap', 'game_card', ['game' => 'word_chain']],
            [180, 'nav', 'push', ['route' => 'game/word_chain']],
            [14500, 'tap', 'game_exit', []],
            [130, 'nav', 'pop_until', ['route' => 'home']],
            // 閒置約 4 分鐘（下課），之後老師想換授權碼
            [252_000, 'app', 'lifecycle', ['state' => 'resumed', 'idle_ms' => 252000]],
            [640, 'tap', 'logo', ['count' => 1]],
            [210, 'tap', 'logo', ['count' => 2]],
            [190, 'tap', 'logo', ['count' => 3]],
            [200, 'tap', 'logo', ['count' => 4]],
            [180, 'tap', 'logo', ['count' => 5]],
            [30, 'auth', 'clear_requested', ['source' => 'logo_5_tap']],
            [25, 'auth', 'state', ['from' => 'authenticated', 'to' => 'unauthenticated']],
            [120, 'nav', 'replace', ['route' => 'auth/license_entry']],
            [9800, 'tap', 'code_field', []],
            [14200, 'tap', 'submit_code', ['length' => 20]],
            [15, 'auth', 'state', ['from' => 'unauthenticated', 'to' => 'authenticating']],
            [10, 'net', 'request', ['method' => 'POST', 'path' => '/api/licensing/v1/activate']],
            [734, 'net', 'response', ['path' => '/api/licensing/v1/activate', 'status' => 200, 'ms' => 734]],
            [22, 'auth', 'activate_ok', ['license_id' => 31, 'scope' => 'T', 'has_content_key' => false]],
            [8, 'auth', 'state', ['from' => 'authenticating', 'to' => 'authenticated']],
            // 教材載入失敗
            [60, 'content', 'reload_start', ['source' => 'usb', 'reason' => 'license_changed']],
            [95, 'content', 'key_missing', ['scope' => 'T', 'detail' => '授權範圍沒有對應的內容金鑰'], 'warn'],
            [180, 'error', 'content_load_failed', ['stage' => 'decrypt_manifest', 'attempt' => 1, 'message' => 'Decryption failed: authentication tag mismatch', 'path' => '/storage/A1B2-C3D4/wilson_content_staging/manifest.json'], 'error'],
            [1020, 'content', 'reload_retry', ['attempt' => 2, 'backoff_ms' => 1000]],
            [165, 'error', 'content_load_failed', ['stage' => 'decrypt_manifest', 'attempt' => 2, 'message' => 'Decryption failed: authentication tag mismatch'], 'error'],
            [2030, 'content', 'reload_retry', ['attempt' => 3, 'backoff_ms' => 2000]],
            [171, 'error', 'content_load_failed', ['stage' => 'decrypt_manifest', 'attempt' => 3, 'message' => 'Decryption failed: authentication tag mismatch', 'gave_up' => true], 'error'],
            [40, 'content', 'gate_blocked', ['screen' => 'ExternalContentGate', 'reason' => 'decrypt_failed'], 'warn'],
            [55, 'nav', 'replace', ['route' => 'content/gate_error']],
            [6400, 'tap', 'retry_button', []],
            [150, 'content', 'reload_start', ['source' => 'usb', 'reason' => 'manual_retry']],
        ];

        $events = [];
        $at = $startedAt->copy();

        foreach ($script as $index => $step) {
            $at = $at->copy()->addMilliseconds($step[0]);

            $events[] = array_filter([
                'seq' => $index + 1,
                't' => $this->iso($at),
                'cat' => $step[1],
                'name' => $step[2],
                'level' => $step[4] ?? 'info',
                'data' => $step[3] ?? [],
            ], fn (mixed $value): bool => $value !== []);
        }

        return $events;
    }

    private function iso(Carbon $time): string
    {
        return $time->copy()->utc()->format('Y-m-d\TH:i:s.v\Z');
    }
}
