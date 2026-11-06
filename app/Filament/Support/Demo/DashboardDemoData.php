<?php

namespace App\Filament\Support\Demo;

use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\Publication;
use App\Models\User;
use App\Models\Website;
use RuntimeException;

/**
 * Dữ liệu mẫu CHỈ để xem thử/test. Bản ghi mẫu: từ khóa bắt đầu '[Mẫu] ', publication_key bắt đầu 'demo-'.
 * Chạy: php artisan tinker --execute="App\Filament\Support\Demo\DashboardDemoData::seed();"
 * Xóa:  php artisan tinker --execute="App\Filament\Support\Demo\DashboardDemoData::purge();"
 */
final class DashboardDemoData
{
    public const PREFIX = '[Mẫu] ';

    private static function guard(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Dữ liệu mẫu chỉ chạy ở môi trường local/testing.');
        }
    }

    public static function seed(): void
    {
        self::guard();
        if (Article::where('primary_keyword', 'like', self::PREFIX . '%')->exists()) {
            return; // idempotent
        }

        $writer = User::where('email', 'writer@company.local')->first();
        $approver = User::where('email', 'approver@company.local')->first();
        $site = Website::where('status', 'active')->orderBy('id')->first()
            ?? Website::create(['name' => 'Website mẫu', 'cms_type' => 'wordpress', 'base_url' => 'https://demo-seo.test', 'encrypted_credentials' => ['demo' => 'x'], 'status' => 'active']);
        Website::firstOrCreate(['base_url' => 'https://demo-khong-lich.test'], ['name' => 'Website mẫu (chưa có lịch)', 'cms_type' => 'wordpress', 'encrypted_credentials' => ['demo' => 'x'], 'status' => 'active']);

        $make = function (string $kw, string $wf, ?string $review, ?int $by, array $v = []) use ($site) {
            $a = Article::create(['website_id' => $site->id, 'created_by' => $by, 'primary_keyword' => self::PREFIX . $kw, 'workflow_status' => $wf]);
            if ($review === null) {
                return null;
            }
            $ver = ArticleVersion::create(['article_id' => $a->id, 'version' => 1, 'title' => self::PREFIX . $kw, 'slug' => 'demo-' . uniqid(), 'html_content' => '<p>Mẫu</p>', 'review_status' => $review] + $v);

            return $ver;
        };

        $recent = $make('chờ duyệt mới (1 giờ)', 'needs_review', 'needs_review', $writer?->id);
        $recent?->forceFill(['created_at' => now()->subHour()])->save();
        $old = $make('quá hạn (30 giờ)', 'needs_review', 'needs_review', $writer?->id);
        $old?->forceFill(['created_at' => now()->subHours(30)])->save();
        $make('bị từ chối', 'rejected', 'rejected', $writer?->id, ['review_notes' => 'Thiếu nguồn dẫn, hãy bổ sung.', 'reviewed_by' => $approver?->id, 'reviewed_at' => now()->subHours(2)]);
        $make('đã lên kế hoạch', 'planned', null, $writer?->id);
        $ok = $make('đã duyệt', 'approved', 'approved', $writer?->id, ['reviewed_by' => $approver?->id, 'reviewed_at' => now()->subHours(5)]);

        $pub = fn (string $key, string $status, array $x = []) => Publication::create([
            'website_id' => $site->id, 'article_version_id' => $ok->id, 'publication_key' => 'demo-' . $key, 'status' => $status, 'timezone' => 'Asia/Ho_Chi_Minh', 'scheduled_at_utc' => now()->utc()->subHour()->format('Y-m-d H:i:s'),
        ] + $x);
        $pub('failed', 'failed', ['last_error' => 'HTTP 401 Authorization: Bearer abcdef1234567890abcdef1234567890']);
        $pub('reconcile', 'needs_reconcile', ['last_error' => 'Timeout sau khi gửi bài. token=sk_live_abcdef1234567890']);
        $pub('scheduled', 'scheduled', ['scheduled_at_utc' => now()->utc()->addHours(5)->format('Y-m-d H:i:s')]);
    }

    public static function purge(): void
    {
        self::guard();
        Publication::where('publication_key', 'like', 'demo-%')->delete();
        $ids = Article::where('primary_keyword', 'like', self::PREFIX . '%')->pluck('id');
        ArticleVersion::whereIn('article_id', $ids)->delete();
        Article::whereIn('id', $ids)->delete();
        Website::where('base_url', 'https://demo-khong-lich.test')->delete();
        Website::where('base_url', 'https://demo-seo.test')->delete();
    }
}
