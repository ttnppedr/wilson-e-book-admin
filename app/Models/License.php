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

    /**
     * key_hash 與 meta（內含授權金鑰的 Crypt 密文 encrypted_key）不得序列化到
     * Livewire/JSON——Filament Edit/View 頁會把非 $hidden 屬性曝露給前端 JS。
     * $hidden 只影響序列化，不影響屬性存取（createWithKey()、retrieveKey()、
     * audit 記錄皆不受影響）。
     *
     * @var list<string>
     */
    protected $hidden = [
        'key_hash',
        'meta',
    ];
}
