<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LucaLongo\Licensing\Models\LicenseTemplate as BaseLicenseTemplate;

class LicenseTemplate extends BaseLicenseTemplate
{
    protected $fillable = [
        'license_scope_id',
        'name',
        'tier_level',
        'parent_template_id',
        'base_configuration',
        'features',
        'entitlements',
        'is_active',
        'meta',
        'supports_trial',
        'trial_duration_days',
        'has_grace_period',
        'grace_period_days',
        'license_duration_days',
        'content_encryption_key_id',
    ];

    public function contentEncryptionKey(): BelongsTo
    {
        return $this->belongsTo(ContentEncryptionKey::class);
    }
}
