<?php

namespace App\Http\Middleware;

use App\Models\License;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use LucaLongo\Licensing\Enums\LicenseStatus;
use Symfony\Component\HttpFoundation\Response;

/**
 * 內容 API 的授權現況把關：確認 token 所屬的 License「現在」仍可用。
 *
 * 為什麼需要這一層：PASETO 是 stateless 憑證，簽章有效 ≠ 授權還有效。vendor 的
 * `PasetoTokenService::verify()` 只檢查簽章、signing key 狀態與 exp/nbf，**完全
 * 不看 License 狀態**。因此在此之前，授權被取消／停權／到期之後，先前簽出的
 * token 仍能無限期存取全部內容 API——永久授權的 token TTL 是 100 年，等同永久
 * 通行證，伺服器端唯一的撤銷手段是輪替 signing key（會一次打掛所有客戶）。
 *
 * 必須排在 VerifyBearerToken 之後：本 middleware 讀的是它寫入的 `license_claims`。
 *
 * 刻意**不**掛在 `licensing/v1/validate` 上：validate 必須在授權已取消／到期時
 * 仍然可達，才能透過 `guardLicenseState()` 回覆帶原因的 410/423 讓 App 分流。
 */
class EnsureLicenseUsable
{
    public function handle(Request $request, Closure $next): Response
    {
        $claims = $request->attributes->get('license_claims');

        $license = is_array($claims) ? $this->resolveLicense($claims) : null;

        if ($license === null || ! $this->isUsable($license)) {
            return $this->unauthorized($license);
        }

        return $next($request);
    }

    /**
     * 由 token claims 取出對應的 License。
     *
     * 刻意只查 License 不查 LicenseUsage：席位為一次性設計，系統中沒有任何路徑
     * 會讓 usage 從 active 變成其他狀態（`over_limit => reject`、
     * `usage_inactivity_auto_revoke_days => null`，後台與 API 皆無撤銷功能），
     * 多一次查詢換不到實際保護。**若日後加入席位撤銷，這裡必須一併補上。**
     *
     * @param  array<string, mixed>  $claims
     */
    private function resolveLicense(array $claims): ?License
    {
        $licenseId = $claims['license_id'] ?? null;

        if (! is_int($licenseId) && ! is_string($licenseId)) {
            return null;
        }

        return License::find($licenseId);
    }

    /**
     * 授權現況判斷，條件與 `LicenseController::guardLicenseState()` 同源。
     */
    private function isUsable(License $license): bool
    {
        if (in_array($license->status, [
            LicenseStatus::Cancelled,
            LicenseStatus::Suspended,
            LicenseStatus::Grace,
        ], true)) {
            return false;
        }

        // 無寬限期，到期即失效。isExpired() 是純日期判斷、不依賴每日排程，
        // 因此授權在到期當下就會被拒，不必等 licensing:check-expirations 跑過。
        return ! $license->isExpired() && $license->isUsable();
    }

    /**
     * 對外一律回 401 INVALID_TOKEN，不分流成 410/423。
     *
     * 內容端點的錯誤契約因此維持單一形狀，不必隨授權狀態機新增狀態而跟著改；
     * App 收到 401 後改打 validate 即可取得帶原因的錯誤碼。真正的狀態只寫進
     * log 供排錯。
     */
    private function unauthorized(?License $license): JsonResponse
    {
        Log::channel('api')->info('[api] license not usable', [
            'internal_code' => 'LICENSE_NOT_USABLE',
            'license_id' => $license?->getKey(),
            'license_status' => $license?->status->value,
        ]);

        return response()->json([
            'success' => false,
            'error' => [
                'code' => 'INVALID_TOKEN',
                'message' => 'License token is missing or invalid',
            ],
        ], 401);
    }
}
