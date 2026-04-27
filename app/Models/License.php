<?php

namespace App\Models;

use App\Models\Concerns\EnforcesAuditEvents;
use LucaLongo\Licensing\Models\License as BaseLicense;
use OwenIt\Auditing\Contracts\Auditable;

class License extends BaseLicense implements Auditable
{
    use EnforcesAuditEvents;
    use \OwenIt\Auditing\Auditable;

    protected $fillable = [
        'key_hash',
        'status',
        'licensable_type',
        'licensable_id',
        'license_scope_id',
        'name',
        'activated_at',
        'expires_at',
        'max_usages',
        'meta',
    ];
}
