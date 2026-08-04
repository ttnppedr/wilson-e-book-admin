<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 為所有回應加上 X-Robots-Tag: noindex，禁止搜尋引擎收錄。
 *
 * 本系統僅供公司內部後台與 App 使用，不需要 SEO。robots.txt 的
 * `Disallow: /` 只能阻止「爬取」，若網址從其他管道（外部連結、
 * Certificate Transparency log）被發現，仍可能以「僅網址」形式
 * 進索引；此 header 是收錄層的權威指令，兜底確保不被收錄。
 *
 * 掛在全域 middleware stack，web（Filament 後台）與 api 回應
 * 一律帶上，包含 401/404 等錯誤回應。
 */
class SetNoIndexHeader
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
