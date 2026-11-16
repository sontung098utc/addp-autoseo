<?php

return [
    // Đường dẫn tới file JSON của service account Google (người dùng tự tạo và cấp; KHÔNG commit file này, KHÔNG để trong repo).
    // Thêm email service account vào Search Console của từng site (quyền "Hạn chế"/Restricted là đủ để đọc).
    'service_account_json' => env('GSC_SERVICE_ACCOUNT_JSON'),

    // 'domain' → thuộc tính "sc-domain:ten-mien.vn"; 'url' → "https://ten-mien.vn/" (theo cách người dùng khai báo trong Search Console).
    'property_type' => env('GSC_PROPERTY_TYPE', 'domain'),

    // Ghi đè thuộc tính theo máy chủ, ví dụ GSC_PROPERTIES="a.vn=sc-domain:a.vn;b.vn=https://b.vn/".
    'properties' => env('GSC_PROPERTIES', ''),

    'endpoint' => 'https://www.googleapis.com/webmasters/v3/sites/{site}/searchAnalytics/query',
    'token_uri' => 'https://oauth2.googleapis.com/token',
    'scope' => 'https://www.googleapis.com/auth/webmasters.readonly',

    'row_limit' => 25000,
    'max_pages' => 8,
    'cache_minutes' => 360,
];
