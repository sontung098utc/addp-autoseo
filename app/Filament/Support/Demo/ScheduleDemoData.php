<?php

namespace App\Filament\Support\Demo;

use App\Filament\Support\Schedule\VnTime;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\Publication;
use App\Models\Website;
use RuntimeException;

/**
 * Dữ liệu MẪU chỉ để xem thử giao diện. Không phải kết quả thật.
 * Chạy: php artisan tinker --execute='App\Filament\Support\Demo\ScheduleDemoData::seed();'
 * Xóa:  php artisan tinker --execute='App\Filament\Support\Demo\ScheduleDemoData::purge();'
 * (Trạng thái "held" chưa có trong enum DB nên không tạo.)
 */
final class ScheduleDemoData
{
    public const KEY_PREFIX = 'demo-';

    public const SITE_URL = 'https://demo-schedule.example.test';

    private static function guard(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Dữ liệu mẫu chỉ được chạy ở môi trường local/testing.');
        }
    }

    public static function seed(): int
    {
        self::guard();

        $site = Website::firstOrCreate(
            ['base_url' => self::SITE_URL],
            ['name' => 'Website mẫu (demo)', 'cms_type' => 'wordpress', 'encrypted_credentials' => ['demo' => true], 'status' => 'active']
        );

        $today = VnTime::now()->startOfDay();
        $fakeError = 'HTTP 401 từ https://demo-schedule.example.test/wp-json/wp/v2/posts?access_token=DEMOFAKETOKEN123 với Authorization: Bearer demoFAKEtoken0123456789abcdefghij';

        $rows = [
            ['01-scheduled-today', 'scheduled', $today->setTime(23, 30), 0, null],
            ['02-scheduled-today-early', 'scheduled', $today->setTime(0, 30), 0, null],
            ['03-scheduled-tomorrow', 'scheduled', $today->addDay()->setTime(9, 0), 0, null],
            ['04-scheduled-next-week', 'scheduled', $today->addWeek()->setTime(10, 0), 0, null],
            ['05-publishing', 'publishing', $today->setTime(8, 0), 1, null],
            ['06-published', 'published', $today->subDay()->setTime(14, 0), 1, null],
            ['07-failed', 'failed', $today->subDay()->setTime(9, 0), 2, $fakeError],
            ['08-needs-reconcile', 'needs_reconcile', $today->setTime(7, 0), 1, 'cURL error 28: Operation timed out after 30000 milliseconds'],
            ['09-needs-reconcile-2', 'needs_reconcile', $today->subDays(2)->setTime(16, 0), 1, 'CMS server error (504): Gateway Time-out'],
            ['10-published-2', 'published', $today->subDays(3)->setTime(11, 0), 1, null],
        ];

        foreach ($rows as [$slug, $status, $at, $attempts, $error]) {
            $article = Article::firstOrCreate(
                ['website_id' => $site->id, 'primary_keyword' => 'demo: từ khóa '.$slug],
                ['workflow_status' => 'approved']
            );
            $version = ArticleVersion::firstOrCreate(
                ['article_id' => $article->id, 'version' => 1],
                ['title' => 'Bài mẫu '.$slug, 'slug' => 'demo-'.$slug, 'html_content' => '<p>Nội dung mẫu</p>', 'review_status' => 'approved']
            );
            $published = $status === 'published';
            Publication::updateOrCreate(
                ['publication_key' => self::KEY_PREFIX.$slug],
                [
                    'website_id' => $site->id,
                    'article_version_id' => $version->id,
                    'scheduled_at_utc' => $at->utc(),
                    'timezone' => VnTime::TZ,
                    'status' => $status,
                    'attempt_count' => $attempts,
                    'last_error' => $error,
                    'cms_post_id' => $published ? 'demo-'.$slug : null,
                    'published_url' => $published ? self::SITE_URL.'/'.$slug : null,
                    'published_at_utc' => $published ? $at->utc() : null,
                ]
            );
        }

        return count($rows);
    }

    public static function purge(): int
    {
        self::guard();

        $deleted = Publication::where('publication_key', 'like', self::KEY_PREFIX.'%')->delete();

        $site = Website::where('base_url', self::SITE_URL)->first();
        if ($site && ! $site->publications()->exists()) {
            Article::where('website_id', $site->id)->where('primary_keyword', 'like', 'demo: %')->delete();
            if (! $site->articles()->exists()) {
                $site->delete();
            }
        }

        return $deleted;
    }
}
