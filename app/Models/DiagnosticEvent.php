<?php

namespace App\Models;

use Database\Factories\DiagnosticEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * App 診斷紀錄的單筆事件。內容全部由裝置自報，**一律視為不可信輸入**。
 */
class DiagnosticEvent extends Model
{
    /** @use HasFactory<DiagnosticEventFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected $fillable = [
        'diagnostic_session_id',
        'seq',
        'occurred_at',
        'category',
        'name',
        'level',
        'data',
        'received_at',
    ];

    protected function casts(): array
    {
        return [
            'diagnostic_session_id' => 'integer',
            'seq' => 'integer',
            'occurred_at' => 'datetime',
            'data' => 'array',
            'received_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<DiagnosticSession, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(DiagnosticSession::class, 'diagnostic_session_id');
    }
}
