<?php

namespace App\Models;

use LucaLongo\Licensing\Models\License as BaseLicense;

class License extends BaseLicense
{
    protected $fillable = [
        'key_hash',
        'status',
        'licensable_type',
        'licensable_id',
        'template_id',
        'license_scope_id',
        'name',
        'activated_at',
        'expires_at',
        'max_usages',
        'meta',
    ];
}
