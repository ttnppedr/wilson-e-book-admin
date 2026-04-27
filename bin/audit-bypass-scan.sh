#!/usr/bin/env bash
#
# audit-bypass-scan
#
# 掃描程式碼是否使用會繞過 owen-it/laravel-auditing 的 API。
# 失敗時 exit 1，可整合到 CI、composer scripts 或 git hook。
#
# 涵蓋範圍：
#   1. Eloquent quiet API：saveQuietly / updateQuietly / deleteQuietly / withoutEvents
#   2. 對 audit target 表直接走 DB::table()（會跳過 model 完全繞過事件）
#
# 不涵蓋（屬於 (e) AST-based 測試的範圍）：
#   - 型別感知的 mass update/delete 偵測
#

set -euo pipefail

readonly TARGET_TABLES=(licenses license_scopes license_usages)
readonly QUIET_METHODS_REGEX='->(saveQuietly|updateQuietly|deleteQuietly)\(|::withoutEvents\(|->withoutEvents\('

declare -i failures=0

scan_dir() {
    local dir="$1"
    [[ -d "$dir" ]] || return 0
    echo "$dir"
}

readonly -a SCAN_DIRS=(app routes database/seeders)

grep_pattern() {
    grep -rEn -e "$1" "${SCAN_DIRS[@]}" --include='*.php' 2>/dev/null || return 1
}

echo "→ 掃描 Eloquent quiet/withoutEvents API ..."
if quiet_hits=$(grep_pattern "$QUIET_METHODS_REGEX"); then
    echo "✗ 偵測到繞過 audit 的 API 呼叫："
    echo "$quiet_hits"
    failures+=1
fi

echo "→ 掃描對 audit target 表的 DB::table() 用法 ..."
for table in "${TARGET_TABLES[@]}"; do
    if db_hits=$(grep_pattern "DB::table\\(['\"]$table['\"]\\)"); then
        echo "✗ 偵測到對 audit target 表 ($table) 直接使用 DB::table()："
        echo "$db_hits"
        failures+=1
    fi
done

if (( failures > 0 )); then
    echo
    echo "audit-bypass-scan FAILED ($failures 類問題)"
    echo "若有合法用途請與團隊評估，或將呼叫改為走 model（save/delete）以保留 audit。"
    exit 1
fi

echo "✓ audit-bypass-scan PASSED"
