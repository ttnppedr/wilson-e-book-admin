{{--
    App 診斷紀錄的時間軸。

    ⚠️ 事件內容（name、data、類別）全部由裝置自報，上傳端點又免驗證，一律視為
    不可信輸入：只能用 {{ }} 輸出，不可使用不 escape 的輸出語法，也不可把事件
    內容寫進 inline script 或 Alpine 運算式。類別鍵要進 class／selector 之前，
    DiagnosticTimelineBuilder 已限制為 [a-z_]。

    樣式刻意寫成本檔內的 <style> 並只用 Filament 輸出的 CSS 變數（--gray-*、
    --danger-* 等）：專案沒有編譯自訂 Tailwind theme，寫新的 utility class 不會
    有對應的 CSS。

    篩選全部在前端完成：Alpine 只切換根元素的 class，實際隱藏交給 CSS 規則，
    所以數千列也不需要逐列綁定，也不會觸發 Livewire 往返。
--}}
@php
    $knownCategories = array_keys(\App\Services\DiagnosticTimelineBuilder::CATEGORY_LABELS);
    $hasTap = collect($categories)->contains('key', 'tap');
@endphp

<div
    class="diag-root"
    wire:ignore.self
    x-data="{
        hidden: [],
        onlyIssues: false,
        isHidden(category) {
            return this.hidden.includes(category)
        },
        toggle(category) {
            this.hidden = this.isHidden(category)
                ? this.hidden.filter((item) => item !== category)
                : [...this.hidden, category]
        },
        showAll() {
            this.hidden = []
            this.onlyIssues = false
        },
        rootClasses() {
            return [
                ...this.hidden.map((category) => 'diag-hide-' + category),
                this.onlyIssues ? 'diag-only-issues' : '',
            ].join(' ')
        },
        jumpToFirstError() {
            const row = this.$root.querySelector('.diag-row[data-level=error]')

            if (! row) {
                return
            }

            this.hidden = this.hidden.filter((item) => item !== row.dataset.cat)

            this.$nextTick(() => {
                row.scrollIntoView({ behavior: 'smooth', block: 'center' })
                row.classList.add('diag-flash')
                setTimeout(() => row.classList.remove('diag-flash'), 2400)
            })
        },
    }"
    x-bind:class="rootClasses()"
>
    <style>
        .diag-root {
            --diag-text: var(--gray-950);
            --diag-muted: var(--gray-500);
            --diag-border: var(--gray-200);
            --diag-subtle: var(--gray-50);
            --diag-surface: #fff;
            --diag-danger-text: var(--danger-600);
            --diag-warning-text: var(--warning-600);
            --diag-c: var(--gray-400);
            color: var(--diag-text);
            font-size: 0.8125rem;
            line-height: 1.5;
        }

        .dark .diag-root {
            --diag-text: var(--gray-50);
            --diag-muted: var(--gray-400);
            --diag-border: color-mix(in oklab, var(--gray-50) 12%, transparent);
            --diag-subtle: color-mix(in oklab, var(--gray-50) 5%, transparent);
            --diag-surface: var(--gray-900);
            --diag-danger-text: var(--danger-400);
            --diag-warning-text: var(--warning-400);
        }

        /* 類別色標。Filament 只有六組語意色，授權與網路另給固定色相（中等明度，亮暗主題皆可辨識）。 */
        .diag-cat-app { --diag-c: var(--gray-500); }
        .diag-cat-nav { --diag-c: var(--info-500); }
        .diag-cat-tap { --diag-c: var(--gray-400); }
        .diag-cat-auth { --diag-c: oklch(0.606 0.25 292.717); }
        .diag-cat-content { --diag-c: var(--success-500); }
        .diag-cat-net { --diag-c: oklch(0.715 0.143 215.221); }
        .diag-cat-error { --diag-c: var(--danger-500); }
        .diag-cat-other { --diag-c: var(--primary-500); }

        .diag-toolbar {
            position: sticky;
            top: 4rem;
            z-index: 5;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            padding: 0.625rem 0;
            margin-bottom: 0.5rem;
            background: var(--diag-surface);
            border-bottom: 1px solid var(--diag-border);
        }

        .diag-toolbar-row {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.375rem 0.5rem;
        }

        .diag-toolbar-label {
            color: var(--diag-muted);
            font-size: 0.75rem;
        }

        .diag-chip {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            padding: 0.1875rem 0.625rem;
            border: 1px solid color-mix(in oklab, var(--diag-c) 45%, transparent);
            border-radius: 999px;
            background: color-mix(in oklab, var(--diag-c) 14%, transparent);
            color: var(--diag-text);
            font-size: 0.75rem;
            cursor: pointer;
            user-select: none;
        }

        .diag-chip:focus-visible {
            outline: 2px solid var(--primary-500);
            outline-offset: 1px;
        }

        .diag-chip-count {
            color: var(--diag-muted);
            font-variant-numeric: tabular-nums;
        }

        .diag-chip-off {
            border-color: var(--diag-border);
            background: transparent;
            color: var(--diag-muted);
            text-decoration: line-through;
        }

        .diag-chip-off .diag-dot {
            background: transparent;
            box-shadow: inset 0 0 0 1.5px var(--diag-muted);
        }

        .diag-switch {
            --diag-c: var(--gray-400);
        }

        .diag-switch-on {
            --diag-c: var(--primary-500);
            border-color: var(--primary-500);
            font-weight: 600;
        }

        .diag-dot {
            flex: none;
            width: 0.5rem;
            height: 0.5rem;
            border-radius: 999px;
            background: var(--diag-c);
        }

        .diag-list {
            border: 1px solid var(--diag-border);
            border-radius: 0.5rem;
            overflow: hidden;
        }

        .diag-row {
            display: grid;
            grid-template-columns: 6.75rem 4.5rem 6.25rem minmax(0, 1fr) auto;
            column-gap: 0.75rem;
            align-items: baseline;
            padding: 0.3125rem 0.75rem;
            border-inline-start: 3px solid var(--diag-c);
            border-bottom: 1px solid var(--diag-border);
        }

        .diag-row:last-child {
            border-bottom: 0;
        }

        .diag-time,
        .diag-delta,
        .diag-seq,
        .diag-name,
        .diag-kv,
        .diag-details pre {
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
        }

        .diag-time {
            font-variant-numeric: tabular-nums;
            white-space: nowrap;
        }

        .diag-delta {
            color: var(--diag-muted);
            font-size: 0.75rem;
            font-variant-numeric: tabular-nums;
            text-align: end;
            white-space: nowrap;
        }

        .diag-delta-long {
            color: var(--diag-text);
            font-weight: 700;
        }

        .diag-cat {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .diag-main {
            display: flex;
            flex-wrap: wrap;
            align-items: baseline;
            gap: 0.125rem 0.75rem;
            min-width: 0;
        }

        .diag-name {
            font-weight: 600;
            overflow-wrap: anywhere;
        }

        .diag-kv {
            color: var(--diag-muted);
            font-size: 0.75rem;
            overflow-wrap: anywhere;
        }

        .diag-kv-value {
            color: var(--diag-text);
        }

        .diag-seq {
            color: var(--diag-muted);
            font-size: 0.6875rem;
            font-variant-numeric: tabular-nums;
            white-space: nowrap;
        }

        .diag-level-tag {
            padding: 0 0.375rem;
            border-radius: 0.25rem;
            color: #fff;
            font-size: 0.6875rem;
            font-weight: 700;
        }

        .diag-row[data-level='error'] {
            border-inline-start-color: var(--danger-500);
            background: color-mix(in oklab, var(--danger-500) 13%, transparent);
        }

        .diag-row[data-level='error'] .diag-name {
            color: var(--diag-danger-text);
        }

        .diag-row[data-level='error'] .diag-level-tag {
            background: var(--danger-600);
        }

        .diag-row[data-level='warn'] {
            border-inline-start-color: var(--warning-500);
            background: color-mix(in oklab, var(--warning-500) 14%, transparent);
        }

        .diag-row[data-level='warn'] .diag-name {
            color: var(--diag-warning-text);
        }

        .diag-row[data-level='warn'] .diag-level-tag {
            background: var(--warning-600);
        }

        /* 收合時與事件名稱同一列；展開後自成一列顯示完整 JSON。 */
        .diag-details[open] {
            flex-basis: 100%;
        }

        .diag-details summary {
            color: var(--diag-muted);
            font-size: 0.6875rem;
            cursor: pointer;
        }

        .diag-details summary:hover {
            color: var(--diag-text);
            text-decoration: underline;
        }

        .diag-details pre {
            margin-top: 0.25rem;
            padding: 0.5rem 0.625rem;
            border: 1px solid var(--diag-border);
            border-radius: 0.375rem;
            background: var(--diag-subtle);
            font-size: 0.75rem;
            white-space: pre-wrap;
            overflow-wrap: anywhere;
        }

        .diag-gap {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.5rem 0.75rem;
            border-bottom: 1px solid var(--diag-border);
            background: var(--diag-subtle);
            color: var(--diag-text);
            font-size: 0.75rem;
            font-weight: 600;
            letter-spacing: 0.02em;
        }

        .diag-gap::before,
        .diag-gap::after {
            content: '';
            flex: 1;
            border-top: 2px dashed var(--diag-muted);
            opacity: 0.6;
        }

        .diag-day {
            padding: 0.25rem 0.75rem;
            border-bottom: 1px solid var(--diag-border);
            background: var(--diag-subtle);
            color: var(--diag-muted);
            font-size: 0.75rem;
            font-weight: 600;
        }

        .diag-flash {
            outline: 2px solid var(--primary-500);
            outline-offset: -2px;
        }

        .diag-note {
            color: var(--diag-muted);
            font-size: 0.75rem;
        }

        .diag-earlier {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 0.5rem;
        }

        .diag-empty {
            padding: 1.5rem;
            color: var(--diag-muted);
            text-align: center;
        }

        .diag-only-issues .diag-row[data-level='info'] {
            display: none;
        }

        @foreach ($categories as $category)
            .diag-hide-{{ $category['key'] }} .diag-row[data-cat='{{ $category['key'] }}'] {
                display: none;
            }
        @endforeach

        @media (max-width: 48rem) {
            .diag-toolbar {
                position: static;
            }

            .diag-row {
                grid-template-columns: auto auto minmax(0, 1fr) auto;
                row-gap: 0.125rem;
            }

            .diag-body {
                grid-column: 1 / -1;
                grid-row: 2;
            }
        }
    </style>

    @if ($loadedCount === 0)
        <div class="diag-empty">這個 session 沒有任何事件。</div>
    @else
        <div class="diag-toolbar">
            <div class="diag-toolbar-row">
                <span class="diag-toolbar-label">類別</span>

                @foreach ($categories as $category)
                    <button
                        type="button"
                        class="diag-chip diag-cat-{{ $category['known'] ? $category['key'] : 'other' }}"
                        data-cat-key="{{ $category['key'] }}"
                        x-on:click="toggle($el.dataset.catKey)"
                        x-bind:class="isHidden($el.dataset.catKey) ? 'diag-chip-off' : ''"
                        x-bind:aria-pressed="(! isHidden($el.dataset.catKey)).toString()"
                        title="點一下切換顯示／隱藏"
                    >
                        <span class="diag-dot"></span>
                        <span>{{ $category['label'] }}</span>
                        <span class="diag-chip-count">{{ number_format($category['count']) }}</span>
                    </button>
                @endforeach
            </div>

            <div class="diag-toolbar-row">
                @if ($hasTap)
                    <button
                        type="button"
                        class="diag-chip diag-switch"
                        x-on:click="toggle('tap')"
                        x-bind:class="isHidden('tap') ? 'diag-switch-on' : ''"
                        x-bind:aria-pressed="isHidden('tap').toString()"
                    >
                        <span class="diag-dot"></span>
                        <span>隱藏點擊事件</span>
                    </button>
                @endif

                <button
                    type="button"
                    class="diag-chip diag-switch"
                    x-on:click="onlyIssues = ! onlyIssues"
                    x-bind:class="onlyIssues ? 'diag-switch-on' : ''"
                    x-bind:aria-pressed="onlyIssues.toString()"
                >
                    <span class="diag-dot"></span>
                    <span>只看警告／錯誤</span>
                    <span class="diag-chip-count">{{ number_format($issueCount) }}</span>
                </button>

                <button
                    type="button"
                    class="diag-chip diag-switch"
                    x-show="hidden.length > 0 || onlyIssues"
                    x-cloak
                    x-on:click="showAll()"
                >
                    全部顯示
                </button>

                @if ($hasLoadedError)
                    <x-filament::button
                        size="xs"
                        color="danger"
                        icon="heroicon-m-arrow-down-circle"
                        x-on:click="jumpToFirstError()"
                    >
                        跳到第一個錯誤
                    </x-filament::button>
                @endif

                <span class="diag-note">
                    @if ($earlierCount > 0)
                        顯示最新 {{ number_format($loadedCount) }} 筆／共 {{ number_format($totalCount) }} 筆事件
                    @else
                        共 {{ number_format($totalCount) }} 筆事件
                    @endif
                </span>
            </div>
        </div>

        @if ($earlierCount > 0)
            <div class="diag-earlier">
                <x-filament::button
                    size="sm"
                    color="gray"
                    icon="heroicon-m-arrow-up"
                    wire:click="loadEarlier"
                    wire:loading.attr="disabled"
                    wire:target="loadEarlier"
                >
                    載入更早的 {{ number_format(min($earlierCount, \App\Services\DiagnosticTimelineBuilder::PAGE_SIZE)) }} 筆
                </x-filament::button>

                <span class="diag-note">
                    還有 {{ number_format($earlierCount) }} 筆更早的事件尚未載入。
                    @unless ($hasLoadedError)
                        目前載入的範圍內沒有錯誤，錯誤可能在更早的事件中。
                    @endunless
                </span>
            </div>
        @endif

        <div class="diag-list" role="list">
            @foreach ($rows as $row)
                @if ($row['type'] === 'day')
                    <div class="diag-day">{{ $row['label'] }}</div>
                @elseif ($row['type'] === 'gap')
                    <div class="diag-gap">{{ $row['label'] }}</div>
                @else
                    <div
                        class="diag-row diag-cat-{{ $row['categoryKnown'] ? $row['category'] : 'other' }}"
                        data-cat="{{ $row['category'] }}"
                        data-level="{{ $row['level'] }}"
                        role="listitem"
                        wire:key="diag-event-{{ $row['seq'] }}"
                    >
                        <div class="diag-time">{{ $row['time'] }}</div>
                        <div @class(['diag-delta', 'diag-delta-long' => $row['deltaIsLong']]) title="與上一筆事件的間隔">{{ $row['delta'] }}</div>
                        <div class="diag-cat">
                            <span class="diag-dot"></span>
                            <span>{{ $row['categoryLabel'] }}</span>
                        </div>
                        <div class="diag-body">
                            <div class="diag-main">
                                @if ($row['level'] === 'error')
                                    <span class="diag-level-tag">錯誤</span>
                                @elseif ($row['level'] === 'warn')
                                    <span class="diag-level-tag">警告</span>
                                @endif

                                <span class="diag-name">{{ $row['name'] }}</span>

                                @foreach ($row['summary'] as $pair)
                                    <span class="diag-kv">{{ $pair['key'] }}=<span class="diag-kv-value">{{ $pair['value'] }}</span></span>
                                @endforeach

                                @if ($row['json'] !== null)
                                    <details class="diag-details">
                                        <summary>完整資料</summary>
                                        <pre>{{ $row['json'] }}</pre>
                                    </details>
                                @endif
                            </div>
                        </div>
                        <div class="diag-seq" title="事件序號">#{{ $row['seq'] }}</div>
                    </div>
                @endif
            @endforeach
        </div>
    @endif
</div>
