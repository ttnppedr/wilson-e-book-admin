<?php

namespace App\Models;

use Database\Factories\DiagnosticSessionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * App 診斷紀錄的一個 session（App 一次啟動到結束所上傳的操作事件）。
 *
 * **不是 audit 對象**：內容由裝置自報、量大且會被保留期清除，事件以批次
 * `insertOrIgnore` 寫入。
 */
class DiagnosticSession extends Model
{
    /** @use HasFactory<DiagnosticSessionFactory> */
    use HasFactory;

    /**
     * 毫秒精度。預設的 `Y-m-d H:i:s` 會在存檔時把毫秒截掉，時間軸的事件間隔
     * 就全部變成整秒。
     */
    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected $fillable = [
        'session_uuid',
        'fingerprint',
        'license_id',
        'license_usage_id',
        'app_version',
        'app_env',
        'platform',
        'os_version',
        'device_model',
        'started_at',
        'first_event_at',
        'last_event_at',
        'event_count',
        'warn_count',
        'error_count',
        'last_error_name',
        'ip',
    ];

    protected function casts(): array
    {
        return [
            'license_id' => 'integer',
            'license_usage_id' => 'integer',
            'started_at' => 'datetime',
            'first_event_at' => 'datetime',
            'last_event_at' => 'datetime',
            'event_count' => 'integer',
            'warn_count' => 'integer',
            'error_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // DB 的 ON DELETE CASCADE 在 SQLite 取決於連線是否開啟 foreign key，
        // 這裡明確先刪事件，兩種驅動行為一致。
        static::deleting(function (DiagnosticSession $session): void {
            $session->events()->delete();
        });
    }

    /**
     * @return BelongsTo<License, $this>
     */
    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }

    /**
     * @return BelongsTo<LicenseUsage, $this>
     */
    public function licenseUsage(): BelongsTo
    {
        return $this->belongsTo(LicenseUsage::class);
    }

    /**
     * @return HasMany<DiagnosticEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(DiagnosticEvent::class);
    }

    /**
     * 第一筆到最後一筆事件之間的毫秒數；尚無事件時為 null。
     */
    public function durationInMilliseconds(): ?int
    {
        if ($this->first_event_at === null || $this->last_event_at === null) {
            return null;
        }

        return (int) round($this->first_event_at->diffInMilliseconds($this->last_event_at));
    }
}
