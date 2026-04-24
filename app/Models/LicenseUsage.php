<?php

namespace App\Models;

use LucaLongo\Licensing\Models\LicenseUsage as BaseLicenseUsage;
use OwenIt\Auditing\Contracts\Auditable;

class LicenseUsage extends BaseLicenseUsage implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $fillable = [
        'license_id',
        'usage_fingerprint',
        'status',
        'registered_at',
        'last_seen_at',
        'revoked_at',
        'client_type',
        'ip',
        'user_agent',
        'meta',
    ];

    /**
     * Heartbeat 只更新 last_seen_at，排除後搭配 AppServiceProvider 的
     * Audit::creating 空值守門員，確保 heartbeat 不產生 audit 記錄。
     *
     * @var array<int, string>
     */
    protected $auditExclude = [
        'last_seen_at',
    ];
}
