<?php

return [
    // Đường dẫn tới file JSON của service account Google (người dùng tự tạo và cấp; KHÔNG commit file này, KHÔNG để trong repo).
    // Thêm email service account vào Search Console của từng site (quyền "Hạn chế"/Restricted là đủ để đọc).
    'service_account_json' => env('GSC_SERVICE_ACCOUNT_JSON'),

    // Cách đăng nhập: 'auto' (service account nếu có khóa, nếu không thì tài khoản Google đã kết nối), 'service_account' hoặc 'oauth'.
    'auth' => env('GSC_AUTH', 'auto'),

    // OAuth bằng tài khoản Google của người dùng (chỉ đọc). Tạo "OAuth client ID" loại Web application trong Google Cloud,
    // khai địa chỉ gọi lại (redirect URI) trùng khớp GSC_OAUTH_REDIRECT_URI. KHÔNG commit giá trị thật.
    'oauth' => [
        'client_id' => env('GSC_OAUTH_CLIENT_ID'),
        'client_secret' => env('GSC_OAUTH_CLIENT_SECRET'),
        'redirect_uri' => env('GSC_OAUTH_REDIRECT_URI'),
        'auth_uri' => 'https://accounts.google.com/o/oauth2/v2/auth',
        'revoke_uri' => 'https://oauth2.googleapis.com/revoke',
        'userinfo_uri' => 'https://openidconnect.googleapis.com/v1/userinfo',
        'email_scope' => 'https://www.googleapis.com/auth/userinfo.email',
    ],

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
