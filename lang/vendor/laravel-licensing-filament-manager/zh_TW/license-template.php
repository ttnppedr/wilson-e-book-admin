<?php

return [
    'fields' => [
        'name' => '範本名稱',
        'slug' => '代稱',
        'tier_level' => '層級',
        'parent_template' => '父範本',
        'is_active' => '啟用中',
        'license_duration_days' => '持續時間',
        'supports_trial' => '試用',
        'trial_duration_days' => '試用持續天數',
        'has_grace_period' => '寬限期',
        'grace_period_days' => '寬限天數',
        'base_configuration' => '基礎設定',
        'features' => '功能',
        'entitlements' => '權限',
        'meta' => '中繼資料',
    ],

    'form' => [
        'details' => '範本詳情',
        'durations' => '持續時間與期間',
        'configuration' => '設定與功能',
        'metadata' => '中繼資料',
    ],

    'actions' => [
        'create' => '新增範本',
    ],

    'filters' => [
        'is_active' => '僅顯示啟用中的範本',
    ],

    'help' => [
        'base_configuration' => '合併至授權基礎設定的鍵值對（例如 max_usages、validity_days）。',
        'features' => '向用戶端公開的功能開關布林值。',
        'entitlements' => '數字或字串權限（限制、容量等）。',
        'license_duration_days' => '授權有效天數。留空表示無期限。',
        'trial_duration_days' => '試用期天數。',
        'grace_period_days' => '授權到期後至完全停用前的天數。',
    ],

    'days' => ':count 天',
];
