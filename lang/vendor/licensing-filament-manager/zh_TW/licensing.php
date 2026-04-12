<?php

return [
    'navigation_group' => '授權管理',

    'resources' => [
        'license' => [
            'navigation_label' => '授權',
            'model_label' => '授權',
            'plural_model_label' => '授權',
        ],
        'license_template' => [
            'navigation_label' => '授權範本',
            'model_label' => '授權範本',
            'plural_model_label' => '授權範本',
        ],
        'license_scope' => [
            'navigation_label' => '授權範圍',
            'model_label' => '授權範圍',
            'plural_model_label' => '授權範圍',
        ],
        'license_usage' => [
            'navigation_label' => '授權使用',
            'model_label' => '授權使用',
            'plural_model_label' => '授權使用',
        ],
    ],

    'pages' => [
        'statistics' => [
            'navigation_label' => '授權統計',
            'title' => '授權統計',
        ],
    ],

    'widgets' => [
        'stats' => [
            'total_licenses' => '授權總數',
            'total_licenses_description' => '系統中的所有授權',
            'active_licenses' => '啟用中授權',
            'active_licenses_description' => '目前啟用中的授權',
            'total_usages' => '使用總數',
            'total_usages_description' => '授權使用紀錄',
            'expiring_soon' => '即將到期',
            'expiring_soon_description' => '未來 30 天內到期的啟用中授權',
            'license_templates' => '授權範本',
            'license_templates_description' => '啟用中的授權範本',
        ],
        'recent_usages' => [
            'heading' => '最近的授權使用',
            'empty_heading' => '沒有授權使用紀錄',
        ],
        'expiring_licenses' => [
            'heading' => '即將到期的授權',
            'empty_heading' => '沒有即將到期的授權',
            'empty_description' => '未來 30 天內沒有到期的授權。',
        ],
    ],

    'fields' => [
        'license_key' => '授權金鑰',
        'key' => '金鑰',
        'scope' => '範圍',
        'scope_id' => '授權範圍',
        'template' => '授權範本',
        'licensable_type' => '可授權類型',
        'licensable_id' => '可授權 ID',
        'expires_at' => '到期時間',
        'is_active' => '是否啟用',
        'created_at' => '建立時間',
        'updated_at' => '更新時間',
        'feature' => '功能',
        'quantity' => '數量',
        'used_at' => '使用時間',
        'days_remaining' => '剩餘天數',
        'device_id' => '裝置 ID',
        'device_name' => '裝置名稱',
        'metadata' => '中繼資料',
        'activated_at' => '啟用時間',
        'deactivated_at' => '停用時間',
    ],

    'actions' => [
        'create' => '建立',
        'edit' => '編輯',
        'view' => '檢視',
        'delete' => '刪除',
        'deactivate' => '停用',
    ],

    'filters' => [
        'active' => '啟用中',
        'inactive' => '未啟用',
        'deactivated' => '已停用',
    ],
];
