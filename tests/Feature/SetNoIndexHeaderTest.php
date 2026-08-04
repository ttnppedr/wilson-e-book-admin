<?php

declare(strict_types=1);

/*
 * 守護「不被搜尋引擎收錄」的兩道防線：
 *  1. 全域 X-Robots-Tag: noindex, nofollow header（SetNoIndexHeader middleware）
 *     —— 收錄層的權威指令，錯誤回應也必須帶上（bot 掃描多半打到 401/404）。
 *  2. robots.txt 的 Disallow: / —— 爬取層公告，順帶減少守規矩爬蟲的流量。
 */

test('web responses carry the noindex header', function () {
    $this->get('/')
        ->assertRedirect('/admin')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
});

test('api error responses also carry the noindex header', function () {
    $this->getJson('/api/v1/wordwalls')
        ->assertStatus(401)
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
});

test('robots txt disallows all crawling', function () {
    $content = file_get_contents(public_path('robots.txt'));

    $this->assertMatchesRegularExpression('/^Disallow: \/$/m', $content);
});
