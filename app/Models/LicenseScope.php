<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\License;
use LucaLongo\Licensing\Models\LicenseScope as BaseLicenseScope;

class LicenseScope extends BaseLicenseScope
{
    protected $fillable = [
        'name',
        'slug',
        'identifier',
        'description',
        'is_active',
        'key_rotation_days',
        'last_key_rotation_at',
        'next_key_rotation_at',
        'default_max_usages',
        'default_duration_days',
        'default_grace_days',
        'content_encryption_key_id',
        'meta',
    ];

    public function licenses(): HasMany
    {
        return $this->hasMany(config('licensing.models.license'));
    }

    public function contentEncryptionKey(): BelongsTo
    {
        return $this->belongsTo(ContentEncryptionKey::class);
    }

    /**
     * 從 Scope 預設值建立 License，允許覆寫。
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createLicense(array $attributes = []): License
    {
        $defaults = [
            'license_scope_id' => $this->getKey(),
            'max_usages' => $this->default_max_usages,
        ];

        if ($this->default_duration_days && ! array_key_exists('expires_at', $attributes)) {
            $defaults['expires_at'] = now()->addDays($this->default_duration_days);
        }

        return License::createWithKey(array_merge($defaults, $attributes));
    }
}
