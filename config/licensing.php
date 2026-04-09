<?php

use App\Models\LicenseScope;
use App\Services\LicenseKeyGenerator;
use App\Services\WilsonPasetoTokenService;
use App\Models\License;
use LucaLongo\Licensing\Models\LicenseRenewal;
use App\Models\LicenseUsage;
use LucaLongo\Licensing\Models\LicensingAuditLog;
use LucaLongo\Licensing\Models\LicensingKey;
use LucaLongo\Licensing\Services\EncryptedLicenseKeyRegenerator;
use LucaLongo\Licensing\Services\EncryptedLicenseKeyRetriever;

return [
    'key_salt' => env('LICENSING_KEY_SALT', env('APP_KEY')),

    'models' => [
        'license_scope' => LicenseScope::class,
        'license' => License::class,
        'license_usage' => LicenseUsage::class,
        'license_renewal' => LicenseRenewal::class,
        'licensing_key' => LicensingKey::class,
        'audit_log' => LicensingAuditLog::class,
    ],

    'services' => [
        'key_generator' => LicenseKeyGenerator::class,
        'key_retriever' => EncryptedLicenseKeyRetriever::class,
        'key_regenerator' => EncryptedLicenseKeyRegenerator::class,
    ],

    'key_management' => [
        'retrieval_enabled' => true, // Allow retrieving original keys
        'regeneration_enabled' => true, // Allow regenerating keys
        'key_prefix' => 'LIC', // Prefix for generated keys
        'key_separator' => '-', // Separator for key segments
    ],

    'policies' => [
        'over_limit' => 'reject', // reject | auto_replace_oldest
        'grace_days' => 14,
        'usage_inactivity_auto_revoke_days' => null, // null to disable
        'unique_usage_scope' => 'license', // license | global
    ],

    'trials' => [
        'enabled' => true,
        'default_duration_days' => 14,
        'allow_extensions' => true,
        'max_extension_days' => 7,
        'prevent_reset_attempts' => true,
        'default_limitations' => [
            // 'max_api_calls' => 1000,
            // 'max_records' => 100,
        ],
        'default_feature_restrictions' => [
            // 'export',
            // 'api_access',
        ],
    ],

    'offline_token' => [
        'enabled' => true,
        'service' => WilsonPasetoTokenService::class,
        'issuer' => env('LICENSING_TOKEN_ISSUER', 'wilson-ebook-admin'),
        'ttl_days' => 7, // fallback，實際由自訂 Controller 動態覆寫為授權剩餘天數
        'force_online_after_days' => 9999, // 不強制上線，完全離線使用
        'clock_skew_seconds' => 60,
    ],

    'crypto' => [
        'algorithm' => 'ed25519', // ed25519 | ES256
        'keystore' => [
            'driver' => 'files', // files | database | custom
            'path' => storage_path('app/licensing/keys'),
            'passphrase' => env('LICENSING_KEY_PASSPHRASE'),
        ],
    ],

    'publishing' => [
        'jwks_url' => null,
        'public_bundle_path' => storage_path('app/licensing/public-bundle.json'),
    ],

    'rate_limit' => [
        'validate_per_minute' => 60,
        'token_per_minute' => 20,
        'register_per_minute' => 30,
    ],

    'audit' => [
        'enabled' => true,
        'store' => 'database', // database | file
        'retention_days' => 90,
        'hash_chain' => true, // Enable hash chaining for tamper-evidence
    ],

    'transfer' => [
        'cooling_period_days' => 30,
        'suspicious_pattern_requires_review' => true,
        'frequent_transfer_window_days' => 90,
        'frequent_transfer_threshold' => 3,
        'high_value_threshold' => 10000,
    ],

    'api' => [
        'enabled' => false, // 路由由 routes/api.php 明確註冊，不使用 vendor 自動註冊
        'prefix' => 'api/licensing/v1',
        'middleware' => ['api', 'throttle:api'],
    ],

    'scheduler' => [
        'check_expirations' => [
            'enabled' => true,
            'time' => '02:00',
        ],
        'cleanup_inactive_usages' => [
            'enabled' => false,
            'time' => '03:00',
        ],
        'notify_expiring' => [
            'enabled' => true,
            'time' => '09:00',
            'days_before' => [30, 14, 7, 3, 1],
        ],
    ],
];
