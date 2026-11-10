<?php

/**
 * Danh sách tên miền tin cậy và bị loại để hệ thống TỰ phân loại nguồn tìm được trên web (luật cố định, không do AI quyết).
 * Mục "trusted" được tự nhận vào kho khi có đoạn nguyên văn hợp lệ; "neutral" chỉ được đề xuất; "blocked" bị bỏ.
 * Mẫu: "who.int" khớp tên miền và mọi tên miền con; "*.gov.vn" khớp mọi tên miền con của gov.vn.
 * Người dùng bổ sung hoặc chặn thêm qua Sổ nguồn của Trí tuệ (Sổ nguồn thắng danh sách này khi chặn).
 * Danh sách chỉ là điểm khởi đầu hợp lý, chưa được chuyên gia thẩm định.
 */
return [
    'general' => ['*.gov.vn', '*.edu.vn', '*.gov', '*.edu', '*.gov.uk', '*.ac.uk'],

    'fields' => [
        'health' => [
            'who.int', 'moh.gov.vn', 'vncdc.gov.vn', 'nih.gov', 'cdc.gov', 'mayoclinic.org', 'nhs.uk', 'diabetes.org', 'idf.org',
            'pubmed.ncbi.nlm.nih.gov', 'ncbi.nlm.nih.gov', 'cochrane.org', 'medlineplus.gov', 'heart.org', 'cancer.org', 'cancer.gov',
            'clevelandclinic.org', 'hopkinsmedicine.org', 'health.harvard.edu', 'nice.org.uk', 'ada.org', 'ema.europa.eu', 'fda.gov',
            'dav.gov.vn', 'vnras.com', 'vien-dinh-duong.org.vn', 'nin.gov.vn', 'bachmai.gov.vn', 'choray.vn',
        ],
        'education' => [
            'moet.gov.vn', 'unesco.org', 'oecd.org', 'britishcouncil.vn', 'britishcouncil.org', 'studyinaustralia.gov.au', 'educationusa.state.gov',
            'studyinuk.org', 'campusfrance.org', 'daad.de', 'ielts.org', 'toefl.org',
        ],
        'finance' => ['sbv.gov.vn', 'mof.gov.vn', 'ssc.gov.vn', 'imf.org', 'worldbank.org', 'bis.org'],
        'legal' => ['moj.gov.vn', 'chinhphu.vn', 'thuvienphapluat.vn', 'vbpl.vn', 'quochoi.vn'],
    ],

    /** Mạng xã hội, diễn đàn, chợ, affiliate, nội dung do người dùng tạo: không dùng làm nguồn. */
    'blocked' => [
        'facebook.com', 'fb.com', 'instagram.com', 'tiktok.com', 'youtube.com', 'youtu.be', 'twitter.com', 'x.com', 'threads.net', 'pinterest.com', 'zalo.me',
        'reddit.com', 'quora.com', 'webtretho.com', 'otofun.net', 'voz.vn', 'tinhte.vn', 'yahoo.com', 'answers.com',
        'shopee.vn', 'lazada.vn', 'tiki.vn', 'sendo.vn', 'amazon.com', 'ebay.com', 'alibaba.com', 'aliexpress.com',
        'medium.com', 'blogspot.com', 'wordpress.com', 'wixsite.com', 'tumblr.com', 'linkedin.com',
        'accesstrade.vn', 'ecomobi.com', 'masoffer.com',
    ],
];
