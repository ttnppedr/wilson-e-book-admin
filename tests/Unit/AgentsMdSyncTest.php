<?php

test('AGENTS.md is CLAUDE.md with only the title lines replaced', function (): void {
    $root = dirname(__DIR__, 2);
    $claudeLines = explode("\n", file_get_contents($root.'/CLAUDE.md'));

    $this->assertSame('# CLAUDE.md', $claudeLines[0]);
    $this->assertStringStartsWith('This file provides guidance to Claude Code', $claudeLines[2]);

    $expectedLines = $claudeLines;
    $expectedLines[0] = '# AGENTS.md';
    $expectedLines[2] = 'This file provides guidance to Codex (Codex.ai/code) when working with code in this repository.';

    $this->assertSame(
        implode("\n", $expectedLines),
        file_get_contents($root.'/AGENTS.md'),
        'AGENTS.md 與 CLAUDE.md 不同步：執行 composer agents-md（bin/sync-agents-md.sh）以 CLAUDE.md 重新產生 AGENTS.md',
    );
});
