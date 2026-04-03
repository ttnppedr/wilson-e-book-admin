<?php

return [
    'form' => [
        'basic_information' => '基本資訊',
        'default_license_settings' => '預設授權設定',
        'default_license_settings_description' => '在此範圍內建立的授權之預設值',
        'key_rotation_settings' => '金鑰輪換設定',
        'key_rotation_settings_description' => '自動簽章金鑰輪換設定',
        'metadata' => '中繼資料',
    ],

    'fields' => [
        'name' => '名稱',
        'slug' => '代稱',
        'slug_help' => 'URL 友善的識別碼（僅限小寫字母、數字和連字號）',
        'identifier' => '識別碼',
        'identifier_help' => 'API 使用的唯一識別碼（例如：com.company.product）',
        'description' => '說明',
        'is_active' => '啟用中',
        'default_max_usages' => '預設最大使用次數',
        'default_duration_days' => '預設持續天數',
        'default_duration_days_help' => '留空表示永久授權',
        'default_grace_days' => '預設寬限天數',
        'key_rotation_days' => '金鑰輪換間隔（天）',
        'key_rotation_days_help' => '設為 0 以停用自動輪換',
        'last_key_rotation_at' => '上次金鑰輪換',
        'next_key_rotation_at' => '下次排程輪換',
        'licenses_count' => '授權總數',
        'active_licenses_count' => '啟用中授權數',
        'meta' => '附加中繼資料',
    ],

    'actions' => [
        'create' => '新增授權範圍',
        'rotate_keys' => '輪換金鑰',
        'rotate_keys_modal_heading' => '輪換簽章金鑰',
        'rotate_keys_modal_description' => '這將撤銷目前啟用的金鑰並產生新金鑰。此操作無法復原。',
        'manual_rotation' => '手動輪換',
    ],

    'filters' => [
        'needs_rotation' => '需要金鑰輪換',
        'has_licenses' => '有授權',
    ],

    'notifications' => [
        'created' => '授權範圍已成功建立。',
        'updated' => '授權範圍已成功更新。',
    ],

    'relations' => [
        'licenses' => '授權',
        'signing_keys' => '簽章金鑰',
        'templates' => '授權範本',
    ],

    'perpetual' => '永久',
    'rotation_days' => ':days 天',
    'disabled' => '已停用',
];
