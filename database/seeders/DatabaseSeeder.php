<?php

namespace Database\Seeders;

use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\MediaAsset;
use App\Models\Publication;
use App\Models\User;
use App\Models\Website;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Tạo 3 người dùng mẫu theo vai trò
        $admin = User::firstOrCreate([
            'email' => 'admin@company.local',
        ], [
            'name' => 'Admin Quản Trị',
            'password' => Hash::make('admin123456'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $approver = User::firstOrCreate([
            'email' => 'approver@company.local',
        ], [
            'name' => 'Trưởng Nhóm Duyệt Bài',
            'password' => Hash::make('approver123'),
            'role' => 'approver',
            'is_active' => true,
        ]);

        $writer = User::firstOrCreate([
            'email' => 'writer@company.local',
        ], [
            'name' => 'Biên Tập Viên Content',
            'password' => Hash::make('writer123'),
            'role' => 'writer',
            'is_active' => true,
        ]);

        // 2. Tạo 2 website kết nối mẫu
        $wpSite = Website::firstOrCreate([
            'base_url' => 'https://blog.company.vn',
            'store_scope' => 'default',
        ], [
            'name' => 'Website Blog Tin Tức Công Ty (WordPress)',
            'cms_type' => 'wordpress',
            'encrypted_credentials' => [
                'username' => 'api_editor',
                'application_password' => 'xxxx-xxxx-xxxx-xxxx',
            ],
            'capability_flags' => [
                'supports_categories' => true,
                'supports_tags' => true,
                'supports_featured_media' => true,
                'supports_seo_metadata' => true,
                'seo_plugin' => 'rank_math',
            ],
            'status' => 'active',
            'last_synced_at' => now(),
        ]);

        $magentoSite = Website::firstOrCreate([
            'base_url' => 'https://shop.company.vn',
            'store_scope' => 'default',
        ], [
            'name' => 'Website Thương Mại Điện Tử (Magento 2)',
            'cms_type' => 'magento2',
            'encrypted_credentials' => [
                'consumer_key' => 'ck_sample_key',
                'consumer_secret' => 'cs_sample_secret',
                'access_token' => 'at_sample_token',
                'access_token_secret' => 'ats_sample_secret',
            ],
            'capability_flags' => [
                'supports_categories' => true,
                'supports_tags' => false,
                'supports_featured_media' => true,
                'supports_seo_metadata' => true,
                'seo_plugin' => 'none',
            ],
            'status' => 'active',
            'last_synced_at' => now(),
        ]);

        // 3. Tạo bài viết mẫu nếu chưa có
        $article1 = Article::firstOrCreate([
            'primary_keyword' => 'giải pháp tự động hóa marketing 2026',
            'website_id' => $wpSite->id,
        ], [
            'created_by' => $writer->id,
            'secondary_keywords' => ['auto seo', 'tăng trưởng organic traffic', 'phần mềm seo nội bộ'],
            'search_intent' => 'informational',
            'topic_cluster' => 'Marketing Automation',
            'workflow_status' => 'approved',
        ]);

        $version1 = ArticleVersion::firstOrCreate([
            'article_id' => $article1->id,
            'version' => 1,
        ], [
            'title' => 'Xu Hướng Tự Động Hóa Marketing & Tối Ưu SEO Trong Năm 2026',
            'slug' => 'xu-huong-tu-dong-hoa-marketing-seo-2026',
            'outline' => [
                '1. Bối cảnh chuyển đổi số marketing',
                '2. Lợi ích của việc tự động hóa lịch xuất bản',
                '3. Đảm bảo chất lượng bài viết và phòng ngừa spam',
                '4. Kết luận và kế hoạch hành động',
            ],
            'html_content' => '<h2>1. Bối cảnh chuyển đổi số marketing</h2><p>Tự động hóa quy trình sản xuất nội dung giúp doanh nghiệp tiết kiệm đến 40% thời gian biên tập viên...</p><h2>2. Lợi ích của việc tự động hóa lịch xuất bản</h2><p>Hệ thống tự động điều phối bài viết theo đúng khung giờ vàng...</p>',
            'seo_title' => 'Xu Hướng Tự Động Hóa Marketing & Tối Ưu SEO Trong Năm 2026 | Company',
            'meta_description' => 'Khám phá các phương pháp tự động hóa quy trình SEO và biên tập nội dung chất lượng cao giúp tăng trưởng organic traffic bền vững.',
            'focus_keyword' => 'giải pháp tự động hóa marketing 2026',
            'faq_schema' => [
                ['question' => 'Tự động hóa SEO có bị phạt không?', 'answer' => 'Nếu nội dung hữu ích, có kiểm chứng và phục vụ người dùng thì hoàn toàn tuân thủ chính sách của Google.'],
            ],
            'sources_cited' => ['Tài liệu nội bộ Company Q3/2026', 'Google Search Central Essentials'],
            'ai_provider' => 'google',
            'ai_model' => 'gemini-1.5-pro',
            'prompt_version' => 'v1.0-seo-vn',
            'tokens_used' => 2450,
            'cost_vnd' => 3100,
            'review_status' => 'approved',
            'reviewed_by' => $approver->id,
            'reviewed_at' => now(),
            'review_notes' => 'Bài viết chuẩn SEO, các đề mục rõ ràng, đã kiểm tra văn phong công ty.',
        ]);

        Publication::firstOrCreate([
            'publication_key' => 'site1-article1-v1',
        ], [
            'website_id' => $wpSite->id,
            'article_version_id' => $version1->id,
            'scheduled_at_utc' => now()->addHours(2),
            'timezone' => 'Asia/Ho_Chi_Minh',
            'status' => 'scheduled',
            'attempt_count' => 0,
        ]);

        // 4. Tạo ảnh mẫu trong thư viện media công ty
        $sampleDir = storage_path('app/public/media/2026/11');
        if (! is_dir($sampleDir)) {
            mkdir($sampleDir, 0755, true);
        }

        $imgFile1 = $sampleDir . '/marketing_automation_banner.webp';
        $thumbFile1 = $sampleDir . '/marketing_automation_banner_thumb.webp';
        if (! file_exists($imgFile1)) {
            $img = imagecreatetruecolor(800, 450);
            $bg = imagecolorallocate($img, 30, 64, 175);
            imagefilledrectangle($img, 0, 0, 800, 450, $bg);
            imagewebp($img, $imgFile1, 85);
            imagedestroy($img);

            $thumb = imagecreatetruecolor(360, 202);
            $thumbBg = imagecolorallocate($thumb, 30, 64, 175);
            imagefilledrectangle($thumb, 0, 0, 360, 202, $thumbBg);
            imagewebp($thumb, $thumbFile1, 80);
            imagedestroy($thumb);
        }

        MediaAsset::firstOrCreate([
            'file_name' => 'marketing_automation_banner.webp',
        ], [
            'website_id' => $wpSite->id,
            'uploaded_by' => $admin->id,
            'disk' => 'public',
            'file_path' => 'media/2026/11/marketing_automation_banner.webp',
            'thumbnail_path' => 'media/2026/11/marketing_automation_banner_thumb.webp',
            'original_name' => 'marketing_automation_banner.png',
            'mime_type' => 'image/webp',
            'file_size' => file_exists($imgFile1) ? filesize($imgFile1) : 45000,
            'width' => 800,
            'height' => 450,
            'sha256' => file_exists($imgFile1) ? hash_file('sha256', $imgFile1) : hash('sha256', 'banner1'),
            'title' => 'Banner Tự Động Hóa Marketing 2026',
            'alt_text' => 'Hình ảnh giải pháp tự động hóa marketing 2026 - Banner tổng quan',
            'caption' => 'Mô hình tự động hóa quy trình phân phối nội dung đa kênh',
            'is_featured' => true,
        ]);
    }
}
