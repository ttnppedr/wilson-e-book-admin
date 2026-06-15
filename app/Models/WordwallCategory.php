<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
     * 圖片的對外完整網址，依預設 filesystem disk（生產環境為 S3）產生；無圖片時為 null。
     *
     * disk 行為：s3 / public disk 的 Storage::url() 本身即回傳絕對網址；預設的 local disk
     * 則回傳相對路徑（如 /storage/...，且因 local disk 為 private visibility 服務時會 403/404）。
     * 為確保對外（原生 App）契約一律是「完整網址」，這裡將相對路徑用 url() 補成絕對網址。
     * 圖片要真正可公開存取，請使用有 url 且 public 的 disk：生產 FILESYSTEM_DISK=s3，
     * 本機開發用 public（並執行 storage:link）。
     *
     * @return Attribute<string|null, never>
     */
    protected function imageUrl(): Attribute
    {
        return Attribute::get(function (): ?string {
            if ($this->image_path === null) {
                return null;
            }

            $url = Storage::url($this->image_path);

            return Str::startsWith($url, ['http://', 'https://']) ? $url : url($url);
        });
    }
}
