<?php

return [
    'form' => [
        'basic_information' => '授權資訊',
        'dates_activation' => '日期與啟用',
        'usage_statistics' => '使用統計',
        'metadata' => '中繼資料',
        'security' => '安全性',
    ],

    'fields' => [
        'id' => '授權 ID',
        'key_hash' => '授權金鑰雜湊',
        'status' => '狀態',
        'license_scope' => '授權範圍',
        'licensable' => '被授權實體',
        'template' => '授權範本',
        'max_usages' => '最大使用次數',
        'usages' => '使用次數',
        'remaining_usages' => '剩餘使用次數',
        'usage_percentage' => '使用率',
        'duration_days' => '持續天數',
        'activated_at' => '啟用時間',
        'expires_at' => '到期時間',
        'meta' => '中繼資料',
        'key_visibility' => '金鑰取回',
        'name' => '名稱',
    ],

    'actions' => [
        'create' => '新增授權',
        'activate' => '啟用',
        'suspend' => '暫停',
        'renew' => '續約',
        'show_key' => '顯示授權金鑰',
        'regenerate_key' => '重新產生授權金鑰',
    ],

    'filters' => [
        'expired' => '已到期',
        'expiring_soon' => '即將到期',
        'over_limit' => '超出使用限制',
    ],

    'help' => [
        'expires_at' => '留空以根據範本預設值或範圍設定自動計算。',
        'template' => '範本控制最大使用次數、有效期、功能和權限。',
        'name' => '標示此授權的使用者（例如：王小明）',
    ],

    'notifications' => [
        'created' => '授權已成功建立。',
        'updated' => '授權已成功更新。',
        'activated' => '授權已成功啟用。',
        'suspended' => '授權已成功暫停。',
        'renewed' => '授權已成功續約。',
        'key_generated' => '授權金鑰已產生。',
        'key_retrieved' => '授權金鑰已就緒。',
        'key_regenerated' => '授權金鑰已重新產生。',
        'key_unavailable' => '無法取回授權金鑰，因為取回功能已停用。',
        'key_value' => '授權金鑰：:key',
    ],

    'statuses' => [
        'pending' => '待啟用',
        'active' => '使用中',
        'grace' => '寬限期',
        'expired' => '已到期',
        'suspended' => '已暫停',
        'cancelled' => '已取消',
    ],

    'relations' => [
        'usages' => '使用紀錄',
        'renewals' => '續約紀錄',
        'transfers' => '移轉紀錄',
        'trials' => '試用紀錄',
    ],

    'security' => [
        'key_not_yet_generated' => '授權金鑰將在儲存後產生。',
        'key_retrievable' => '加密金鑰取回功能已啟用。',
        'key_not_retrievable' => '授權設定中已停用金鑰取回。',
        'key_not_stored' => '此授權未儲存金鑰，僅新建立的授權才有金鑰可取回。',
    ],
];
