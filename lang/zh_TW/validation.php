<?php

return [

    /*
    |--------------------------------------------------------------------------
    | 驗證錯誤訊息語系
    |--------------------------------------------------------------------------
    |
    | 以下語系字串是 Laravel Validator 使用的預設錯誤訊息,
    | 部分規則有多種版本(例如 size 規則會依型別有不同訊息),
    | 歡迎依專案需求調整這些內容。
    |
    */

    'accepted' => ':attribute 欄位必須接受。',
    'accepted_if' => '當 :other 為 :value 時,:attribute 欄位必須接受。',
    'active_url' => ':attribute 欄位必須為有效的網址。',
    'after' => ':attribute 欄位必須為 :date 之後的日期。',
    'after_or_equal' => ':attribute 欄位必須為 :date 當日或之後的日期。',
    'alpha' => ':attribute 欄位只能包含字母。',
    'alpha_dash' => ':attribute 欄位只能包含字母、數字、連字號和底線。',
    'alpha_num' => ':attribute 欄位只能包含字母和數字。',
    'any_of' => ':attribute 欄位無效。',
    'array' => ':attribute 欄位必須為陣列。',
    'ascii' => ':attribute 欄位只能包含單位元組的英數字元與符號。',
    'before' => ':attribute 欄位必須為 :date 之前的日期。',
    'before_or_equal' => ':attribute 欄位必須為 :date 當日或之前的日期。',
    'between' => [
        'array' => ':attribute 欄位必須包含 :min 到 :max 個項目。',
        'file' => ':attribute 欄位大小必須介於 :min 到 :max KB 之間。',
        'numeric' => ':attribute 欄位必須介於 :min 到 :max 之間。',
        'string' => ':attribute 欄位長度必須介於 :min 到 :max 個字元之間。',
    ],
    'boolean' => ':attribute 欄位必須為 true 或 false。',
    'can' => ':attribute 欄位包含未授權的值。',
    'confirmed' => ':attribute 欄位的確認輸入不相符。',
    'contains' => ':attribute 欄位缺少必要的值。',
    'current_password' => '密碼錯誤。',
    'date' => ':attribute 欄位必須為有效的日期。',
    'date_equals' => ':attribute 欄位必須為 :date 當日的日期。',
    'date_format' => ':attribute 欄位必須符合 :format 格式。',
    'decimal' => ':attribute 欄位必須為 :decimal 位小數。',
    'declined' => ':attribute 欄位必須為拒絕。',
    'declined_if' => '當 :other 為 :value 時,:attribute 欄位必須為拒絕。',
    'different' => ':attribute 欄位與 :other 必須不同。',
    'digits' => ':attribute 欄位必須為 :digits 位數字。',
    'digits_between' => ':attribute 欄位必須為 :min 到 :max 位數字。',
    'dimensions' => ':attribute 欄位的圖片尺寸無效。',
    'distinct' => ':attribute 欄位有重複的值。',
    'doesnt_contain' => ':attribute 欄位不可包含以下任一值: :values。',
    'doesnt_end_with' => ':attribute 欄位結尾不可為以下任一值: :values。',
    'doesnt_start_with' => ':attribute 欄位開頭不可為以下任一值: :values。',
    'email' => ':attribute 欄位必須為有效的電子郵件地址。',
    'encoding' => ':attribute 欄位必須使用 :encoding 編碼。',
    'ends_with' => ':attribute 欄位結尾必須為以下任一值: :values。',
    'enum' => '所選的 :attribute 無效。',
    'exists' => '所選的 :attribute 無效。',
    'extensions' => ':attribute 欄位副檔名必須為以下其中一種: :values。',
    'file' => ':attribute 欄位必須為檔案。',
    'filled' => ':attribute 欄位必須有值。',
    'gt' => [
        'array' => ':attribute 欄位必須包含超過 :value 個項目。',
        'file' => ':attribute 欄位大小必須大於 :value KB。',
        'numeric' => ':attribute 欄位必須大於 :value。',
        'string' => ':attribute 欄位長度必須大於 :value 個字元。',
    ],
    'gte' => [
        'array' => ':attribute 欄位必須包含 :value 個或以上的項目。',
        'file' => ':attribute 欄位大小必須大於或等於 :value KB。',
        'numeric' => ':attribute 欄位必須大於或等於 :value。',
        'string' => ':attribute 欄位長度必須大於或等於 :value 個字元。',
    ],
    'hex_color' => ':attribute 欄位必須為有效的十六進位色碼。',
    'image' => ':attribute 欄位必須為圖片。',
    'in' => '所選的 :attribute 無效。',
    'in_array' => ':attribute 欄位必須存在於 :other 中。',
    'in_array_keys' => ':attribute 欄位至少必須包含以下其中一個 key: :values。',
    'integer' => ':attribute 欄位必須為整數。',
    'ip' => ':attribute 欄位必須為有效的 IP 位址。',
    'ipv4' => ':attribute 欄位必須為有效的 IPv4 位址。',
    'ipv6' => ':attribute 欄位必須為有效的 IPv6 位址。',
    'json' => ':attribute 欄位必須為有效的 JSON 字串。',
    'list' => ':attribute 欄位必須為清單。',
    'lowercase' => ':attribute 欄位必須為小寫。',
    'lt' => [
        'array' => ':attribute 欄位必須包含少於 :value 個項目。',
        'file' => ':attribute 欄位大小必須小於 :value KB。',
        'numeric' => ':attribute 欄位必須小於 :value。',
        'string' => ':attribute 欄位長度必須小於 :value 個字元。',
    ],
    'lte' => [
        'array' => ':attribute 欄位不可超過 :value 個項目。',
        'file' => ':attribute 欄位大小必須小於或等於 :value KB。',
        'numeric' => ':attribute 欄位必須小於或等於 :value。',
        'string' => ':attribute 欄位長度必須小於或等於 :value 個字元。',
    ],
    'mac_address' => ':attribute 欄位必須為有效的 MAC 位址。',
    'max' => [
        'array' => ':attribute 欄位不可超過 :max 個項目。',
        'file' => ':attribute 欄位大小不可超過 :max KB。',
        'numeric' => ':attribute 欄位不可大於 :max。',
        'string' => ':attribute 欄位長度不可超過 :max 個字元。',
    ],
    'max_digits' => ':attribute 欄位不可超過 :max 位數字。',
    'mimes' => ':attribute 欄位檔案類型必須為: :values。',
    'mimetypes' => ':attribute 欄位檔案類型必須為: :values。',
    'min' => [
        'array' => ':attribute 欄位至少必須包含 :min 個項目。',
        'file' => ':attribute 欄位大小至少必須為 :min KB。',
        'numeric' => ':attribute 欄位至少必須為 :min。',
        'string' => ':attribute 欄位長度至少必須為 :min 個字元。',
    ],
    'min_digits' => ':attribute 欄位至少必須為 :min 位數字。',
    'missing' => ':attribute 欄位必須不存在。',
    'missing_if' => '當 :other 為 :value 時,:attribute 欄位必須不存在。',
    'missing_unless' => '除非 :other 為 :value,否則 :attribute 欄位必須不存在。',
    'missing_with' => '當 :values 存在時,:attribute 欄位必須不存在。',
    'missing_with_all' => '當 :values 皆存在時,:attribute 欄位必須不存在。',
    'multiple_of' => ':attribute 欄位必須為 :value 的倍數。',
    'not_in' => '所選的 :attribute 無效。',
    'not_regex' => ':attribute 欄位格式無效。',
    'numeric' => ':attribute 欄位必須為數字。',
    'password' => [
        'letters' => ':attribute 欄位至少必須包含一個字母。',
        'mixed' => ':attribute 欄位至少必須包含一個大寫與一個小寫字母。',
        'numbers' => ':attribute 欄位至少必須包含一個數字。',
        'symbols' => ':attribute 欄位至少必須包含一個符號。',
        'uncompromised' => '輸入的 :attribute 曾出現在資料外洩事件中,請改用其他 :attribute。',
    ],
    'present' => ':attribute 欄位必須存在。',
    'present_if' => '當 :other 為 :value 時,:attribute 欄位必須存在。',
    'present_unless' => '除非 :other 為 :value,否則 :attribute 欄位必須存在。',
    'present_with' => '當 :values 存在時,:attribute 欄位必須存在。',
    'present_with_all' => '當 :values 皆存在時,:attribute 欄位必須存在。',
    'prohibited' => ':attribute 欄位為禁止填寫。',
    'prohibited_if' => '當 :other 為 :value 時,:attribute 欄位為禁止填寫。',
    'prohibited_if_accepted' => '當 :other 為接受時,:attribute 欄位為禁止填寫。',
    'prohibited_if_declined' => '當 :other 為拒絕時,:attribute 欄位為禁止填寫。',
    'prohibited_unless' => '除非 :other 為 :values 中的其中一個,否則 :attribute 欄位為禁止填寫。',
    'prohibits' => ':attribute 欄位會使 :other 不得存在。',
    'regex' => ':attribute 欄位格式無效。',
    'required' => ':attribute 欄位為必填。',
    'required_array_keys' => ':attribute 欄位必須包含以下 key: :values。',
    'required_if' => '當 :other 為 :value 時,:attribute 欄位為必填。',
    'required_if_accepted' => '當 :other 為接受時,:attribute 欄位為必填。',
    'required_if_declined' => '當 :other 為拒絕時,:attribute 欄位為必填。',
    'required_unless' => '除非 :other 為 :values 中的其中一個,否則 :attribute 欄位為必填。',
    'required_with' => '當 :values 存在時,:attribute 欄位為必填。',
    'required_with_all' => '當 :values 皆存在時,:attribute 欄位為必填。',
    'required_without' => '當 :values 不存在時,:attribute 欄位為必填。',
    'required_without_all' => '當 :values 皆不存在時,:attribute 欄位為必填。',
    'same' => ':attribute 欄位必須與 :other 相符。',
    'size' => [
        'array' => ':attribute 欄位必須包含 :size 個項目。',
        'file' => ':attribute 欄位大小必須為 :size KB。',
        'numeric' => ':attribute 欄位必須為 :size。',
        'string' => ':attribute 欄位長度必須為 :size 個字元。',
    ],
    'starts_with' => ':attribute 欄位開頭必須為以下任一值: :values。',
    'string' => ':attribute 欄位必須為字串。',
    'timezone' => ':attribute 欄位必須為有效的時區。',
    'unique' => ':attribute 已經被使用。',
    'uploaded' => ':attribute 上傳失敗。',
    'uppercase' => ':attribute 欄位必須為大寫。',
    'url' => ':attribute 欄位必須為有效的網址。',
    'ulid' => ':attribute 欄位必須為有效的 ULID。',
    'uuid' => ':attribute 欄位必須為有效的 UUID。',

    /*
    |--------------------------------------------------------------------------
    | 自訂驗證錯誤訊息
    |--------------------------------------------------------------------------
    |
    | 可以在此使用 "attribute.rule" 命名慣例為特定欄位與規則的組合指定
    | 自訂錯誤訊息。
    |
    */

    'custom' => [
        'attribute-name' => [
            'rule-name' => 'custom-message',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 自訂驗證欄位名稱
    |--------------------------------------------------------------------------
    |
    | 以下語系字串用於將驗證訊息中的 :attribute 替換為更易讀的名稱,
    | 例如把 "email" 顯示為 "電子郵件地址"。
    |
    */

    'attributes' => [],

];
