<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class WordwallCategory extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'image_path',
        'sort',
    ];

    protected function casts(): array
    {
        return [
            'sort' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // 換圖時移除被取代的舊圖檔，避免在 S3 上累積孤兒檔。
        // updated 事件在 save 之後、syncOriginal 之前觸發，故此時 getOriginal() 仍為舊路徑、
        // wasChanged() 反映本次變更；只刪「真的被換掉」且原本存在的圖。
        static::updated(function (WordwallCategory $category): void {
            if (! $category->wasChanged('image_path')) {
                return;
            }

            $previousPath = $category->getOriginal('image_path');

            if ($previousPath !== null) {
                Storage::disk(config('filesystems.default'))->delete($previousPath);
            }
        });

        // 刪除分類時一併移除其圖片檔，避免在 S3 上累積孤兒檔。
        // 透過 Filament DeleteAction 走單筆 $record->delete() 會觸發此事件。
        static::deleted(function (WordwallCategory $category): void {
            if ($category->image_path !== null) {
                Storage::disk(config('filesystems.default'))->delete($category->image_path);
            }
        });
    }

    /**
     * 此分類底下的 Wordwall 遊戲。
     *
     * @return HasMany<Wordwall, $this>
     */
    public function wordwalls(): HasMany
    {
        return $this->hasMany(Wordwall::class);
    }

    /**
     * 圖片的對外網址：指向「需 bearer token 的轉址端點」，無圖片時為 null。
     *
     * 安全性：S3 物件全程維持 private（bucket 不對外公開、不可被列舉/盜連）。此處刻意
     * 回傳穩定的應用端點網址（不含 S3 簽章），由 WordwallCategoryImageController 在請求
     * 當下簽發 presigned 短效網址並 302 轉址。如此同時滿足：
     *   - 物件不必公開即可受控存取；
     *   - 清單回應的「內容型 ETag」維持穩定 —— presigned URL 每次的簽章/時間戳都不同，
     *     若直接放進清單會讓 ETag 每次失效、304 條件式快取全毀。
     *
     * v 參數綁定目前圖檔（image_path 的雜湊片段，亦避免外洩原始儲存路徑）：換圖時即改變
     * → 清單 body 變動 → ETag 失效 → App 重抓清單並改抓新圖，避免 client 端取到舊圖快取。
     *
     * @return Attribute<string|null, never>
     */
    protected function imageUrl(): Attribute
    {
        return Attribute::get(function (): ?string {
            if ($this->image_path === null) {
                return null;
            }

            return route('v1.wordwall-categories.image', [
                'wordwallCategory' => $this->id,
                'v' => substr(md5($this->image_path), 0, 8),
            ]);
        });
    }
}
