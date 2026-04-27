<?php

namespace App\Models\Concerns;

use LogicException;

/**
 * 為 audit target model 阻擋 Eloquent 的 quiet API。
 *
 * License/LicenseScope/LicenseUsage 透過 owen-it/laravel-auditing 記錄變更，
 * 而 audit 監聽 Eloquent 的 created/updated/deleted 事件。
 * Eloquent 的 saveQuietly()/updateQuietly()/deleteQuietly() 會在不觸發事件的前提下寫入資料庫，
 * 直接導致 audit 漏記。此 trait 將這幾個方法覆寫為拋出 LogicException，
 * 提供 runtime 保護，避免靜態掃描遺漏的呼叫進入 production。
 *
 * 全域層級的 Model::withoutEvents() 仍無法在此攔截，需要由 bin/audit-bypass-scan.sh 在 CI 階段擋下。
 */
trait EnforcesAuditEvents
{
    /**
     * @param  array<string, mixed>  $options
     */
    public function saveQuietly(array $options = []): bool
    {
        throw $this->auditBypassException(__FUNCTION__);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $options
     */
    public function updateQuietly(array $attributes = [], array $options = []): bool
    {
        throw $this->auditBypassException(__FUNCTION__);
    }

    public function deleteQuietly(): ?bool
    {
        throw $this->auditBypassException(__FUNCTION__);
    }

    private function auditBypassException(string $method): LogicException
    {
        return new LogicException(sprintf(
            '%s 為 audit target，禁止呼叫 %s()。請改用會觸發 Eloquent 事件的對等方法（save/update/delete）。',
            static::class,
            $method,
        ));
    }
}
