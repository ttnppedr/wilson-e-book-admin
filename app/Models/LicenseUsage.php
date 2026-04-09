<?php

namespace App\Models;

use LucaLongo\Licensing\Models\LicenseUsage as BaseLicenseUsage;

class LicenseUsage extends BaseLicenseUsage
{
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
}
