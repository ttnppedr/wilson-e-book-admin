<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Wordwall extends Model
{
    use HasFactory;

    protected $fillable = [
        'resource_url',
        'wordwall_category_id',
        'sort',
    ];

    protected function casts(): array
    {
        return [
            'wordwall_category_id' => 'integer',
            'sort' => 'integer',
        ];
    }

    /**
     * 此 Wordwall 所屬的遊戲分類（可為 null，代表未分類）。
     *
     * @return BelongsTo<WordwallCategory, $this>
     */
    public function category(): BelongsTo
    {
        // 必須明確指定外鍵：belongsTo 預設依方法名推導為 category_id，但實際欄位是 wordwall_category_id。
        return $this->belongsTo(WordwallCategory::class, 'wordwall_category_id');
    }
}
