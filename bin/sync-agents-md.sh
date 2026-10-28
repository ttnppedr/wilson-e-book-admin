#!/usr/bin/env bash
#
# sync-agents-md
#
# 以 CLAUDE.md 重新產生 AGENTS.md（Codex 用的副本）。
# 兩檔只差第 1、3 行標題，其餘逐字相同；不要直接編輯 AGENTS.md。
#
# 時機：改完 CLAUDE.md 或跑完 boost:update 後執行一次。
# 由 tests/Unit/AgentsMdSyncTest.php 守護。
#

set -euo pipefail

cd "$(dirname "$0")/.."

sed \
    -e '1s/.*/# AGENTS.md/' \
    -e '3s|.*|This file provides guidance to Codex (Codex.ai/code) when working with code in this repository.|' \
    CLAUDE.md > AGENTS.md

echo "AGENTS.md 已由 CLAUDE.md 重新產生"
