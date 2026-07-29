<?php

namespace App\Models;

use App\Models\Concerns\EnforcesAuditEvents;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use LogicException;
use LucaLongo\Licensing\Models\LicenseScope as BaseLicenseScope;
use OwenIt\Auditing\Contracts\Auditable;

class LicenseScope extends BaseLicenseScope implements Auditable
{
    use EnforcesAuditEvents;
    use \OwenIt\Auditing\Auditable;

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

    /**
     * meta 為自由格式欄位，後台表單未使用，
     * 不隨 Filament/Livewire 序列化下發到前端。
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'meta',
    ];

    protected static function booted(): void
    {
        parent::booted();

        static::creating(function (self $scope): void {
            $baseSlug = 'scope-'.strtolower(Str::random(8));
            $scope->slug = static::makeUniqueSlug($baseSlug);
            $scope->identifier = $scope->slug;
        });

        // `licenses.license_scope_id` 的外鍵是 `nullOnDelete()`，所以刪掉 scope 會由
        // **DB 引擎**把旗下所有 License 的 license_scope_id 清成 NULL。這條路徑有兩個
        // 問題：(1) 不派發 Eloquent 事件，audit 一列都不寫——CLAUDE.md 列的 5 層防護
        // 全部只看 PHP 層，看不到 DB schema，因此完全攔不到；(2) content encryption key
        // 是掛在 scope 上的，關聯一斷，該產品線每一張授權的 activate 都會落入
        // MISSING_CONTENT_KEY 而永久回 500。
        //
        // 在 model 層擋住是唯一能涵蓋所有入口的位置（Filament 兩處 DeleteAction、
        // tinker、artisan command 都會經過）。UI 端另有守門把按鈕藏起來，所以正常
        // 操作不會走到這裡；會走到就代表繞過了 UI，此時寧可大聲炸開也不要靜默毀資料。
        static::deleting(function (self $scope): void {
            if ($scope->licenses()->exists()) {
                throw new LogicException(
                    "LicenseScope [{$scope->getKey()}] 底下仍有 License，不可刪除："
                    .'刪除會由 DB 的 on delete set null 靜默清空這些授權的 license_scope_id，'
                    .'既不留 audit，也會讓它們失去 content encryption key。'
                    .'請先改派或刪除旗下的 License。'
                );
            }
        });
    }

    private static function makeUniqueSlug(string $baseSlug): string
    {
        $slug = $baseSlug;
        $counter = 1;

        while (static::where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$counter;
            $counter++;
        }

        return $slug;
    }

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
