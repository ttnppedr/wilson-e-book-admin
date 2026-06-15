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
        return $this->belongsTo(WordwallCategory::class);
    }
}
