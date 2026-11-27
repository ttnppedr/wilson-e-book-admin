<?php

namespace App\Services;

/**
 * 一次診斷上傳的處理結果。
 *
 * 回應給 App 的只有 `accepted`；其餘欄位寫進 api log，讓「App 說有上傳、後台卻
 * 沒資料」事後查得出原因——`accepted: 0` 有三種成因，光看回應分不出來。
 */
final readonly class DiagnosticUploadResult
{
    /** 有事件新寫入 */
    public const OUTCOME_STORED = 'stored';

    /** 指紋對不上任何席位，整批未保存 */
    public const OUTCOME_UNKNOWN_FINGERPRINT = 'unknown_fingerprint';

    /** session 已綁定別的指紋，整批未保存 */
    public const OUTCOME_FINGERPRINT_MISMATCH = 'fingerprint_mismatch';

    /** 整批都是先前已收過的事件（App 重送） */
    public const OUTCOME_DUPLICATE_ONLY = 'duplicate_only';

    /**
     * @param  array<string, mixed>  $context  寫進 log 的補充資訊
     */
    public function __construct(
        public string $outcome,
        public int $accepted = 0,
        public array $context = [],
    ) {}
}
