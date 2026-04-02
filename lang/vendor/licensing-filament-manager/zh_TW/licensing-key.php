<?php

return [
    'fields' => [
        'kid' => '金鑰 ID',
        'status' => '狀態',
        'algorithm' => '演算法',
        'valid_from' => '有效起始',
        'valid_until' => '有效截止',
        'revoked_at' => '撤銷時間',
        'revocation_reason' => '撤銷原因',
    ],

    'actions' => [
        'generate_new' => '產生新金鑰',
        'generate_new_modal_heading' => '產生新簽章金鑰',
        'generate_new_modal_description' => '這將為此範圍建立一個新的簽章金鑰。',
        'revoke' => '撤銷金鑰',
        'revoke_modal_heading' => '撤銷簽章金鑰',
        'revoke_modal_description' => '這將永久撤銷此簽章金鑰。此操作無法復原。',
        'revoke_selected' => '撤銷所選金鑰',
    ],

    'filters' => [
        'expired' => '已過期的金鑰',
    ],

    'notifications' => [
        'generated' => '簽章金鑰已成功產生。',
        'generated_body' => '新簽章金鑰已核發：:kid',
        'revoked' => '簽章金鑰已撤銷。',
        'failed' => '無法產生簽章金鑰。',
    ],
];
