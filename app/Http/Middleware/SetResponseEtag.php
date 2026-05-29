<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 為可快取的 GET 回應加上「內容型 ETag」，並處理 If-None-Match 條件式請求。
 *
 * 背景：電子白板 App 進入遊戲目錄時會以 stale-while-revalidate 方式背景
 * 重新整理 Wordwall 清單。清單內容若未變動，回傳 304 Not Modified（無
 * body），省下傳輸與 client 端 JSON 解析成本；內容一變動，雜湊即改變、
 * 回傳 200 與新清單。
 *
 * 設計：
 *  - ETag 以「回應 body 的 md5」計算 —— 清單任何變動（新增／刪除／改 sort
 *    或 resource_url）都會反映在 body、進而改變 ETag，無需額外維護版本欄位。
 *  - 僅處理可快取方法（GET/HEAD）的 200 回應；其餘（401/429/錯誤）原樣放行。
 *  - 套用於需要的路由即可（見 routes/api.php），不全域啟用以降低影響面。
 */
class SetResponseEtag
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->isMethodCacheable()) {
            return $response;
        }

        if ($response->getStatusCode() !== Response::HTTP_OK) {
            return $response;
        }

        $content = $response->getContent();
        if ($content === false) {
            return $response;
        }

        $response->setEtag(md5($content));

        // 命中 If-None-Match → Symfony 會把回應轉成 304 並清空 body。
        $response->isNotModified($request);

        return $response;
    }
}
