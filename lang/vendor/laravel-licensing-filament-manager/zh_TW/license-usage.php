<?php

return [
    'fields' => [
        'usage_fingerprint' => '使用指紋',
        'status' => '狀態',
        'client_type' => '用戶端類型',
        'name' => '名稱',
        'ip' => 'IP 位址',
        'user_agent' => '使用者代理',
        'registered_at' => '註冊時間',
        'last_seen_at' => '最後使用時間',
        'revoked_at' => '撤銷時間',
    ],

    'actions' => [
        'revoke' => '撤銷使用',
        'revoke_selected' => '撤銷所選項目',
        'heartbeat' => '更新心跳',
    ],

    'filters' => [
        'inactive' => '未活躍（超過 7 天）',
    ],

    'help' => [
        'usage_fingerprint' => '通常是裝置或安裝識別碼的雜湊值。',
    ],

    'notifications' => [
        'revoked' => '使用已成功撤銷。',
    ],
];
