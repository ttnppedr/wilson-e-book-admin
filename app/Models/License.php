<?php

namespace App\Models;

use App\Models\Concerns\EnforcesAuditEvents;
use LucaLongo\Licensing\Enums\LicenseStatus;
use LucaLongo\Licensing\Events\LicenseExpired;
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

    /**
     * 產品決策：**本專案不提供寬限期，授權到期即失效**。
     *
     * 覆寫成 no-op 而非只是拿掉呼叫點——vendor 自己的 CheckExpirationsCommand
     * （src/Commands/CheckExpirationsCommand.php:58）也會呼叫這支，覆寫在
     * model 層才能保證沒有任何路徑走得進 Grace。
     *
     * 另註：App\Enums\LicenseStatusLabel 本來就沒有 Grace case，後台若真的
     * 出現 grace 授權會直接炸開——這個狀態從來沒有被完整支援過。
     */
    public function transitionToGrace(): self
    {
        return $this;
    }

    /**
     * 會被 `expires_at` 推動轉為 Expired 的狀態。
     *
     * Suspended/Cancelled/Pending 刻意排除：那是管理者的明確決定，不該因為
     * expires_at 過了就被改寫。Grace 納入僅為收斂舊資料（新流程不再產生）。
     *
     * 定義在此是為了讓 CheckLicenseExpirationsCommand 的 whereIn 與下方
     * transitionToExpired() 的 in_array 同源——同一條產品規則若各寫一次，
     * 日後新增狀態漏改其一只會導致該批 row 靜默不被掃到，不會報錯。
     *
     * @var list<LicenseStatus>
     */
    public const EXPIRABLE_STATUSES = [LicenseStatus::Active, LicenseStatus::Grace];

    /**
     * 覆寫 vendor transitionToExpired()：允許 Active 直接轉 Expired。
     *
     * vendor 原版要求 `isInGracePeriod() && gracePeriodExpired()`，也就是**只能**
     * 由 Grace 轉入。既然本專案永遠不進 Grace，沿用原版會讓已到期的授權永遠停在
     * Active。改為「Active 或（legacy 殘留的）Grace + expires_at 已過」即轉。
     *
     * 刻意只涵蓋 Active/Grace：Suspended/Cancelled/Pending 是管理者的明確決定，
     * 不該因為 expires_at 過了就被這支改寫。
     * 對齊版本：vendor 2.2.1
     */
    public function transitionToExpired(): self
    {
        if (! in_array($this->status, self::EXPIRABLE_STATUSES, true)) {
            return $this;
        }

        if (! $this->isExpired()) {
            return $this;
        }

        $this->update(['status' => LicenseStatus::Expired]);
        event(new LicenseExpired($this));

        return $this;
    }
}
