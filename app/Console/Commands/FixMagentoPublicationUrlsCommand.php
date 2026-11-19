<?php

namespace App\Console\Commands;

use App\Filament\Support\Audit\LogAuditSink;
use App\Integrations\Magento\MagentoBlogUrl;
use App\Models\Publication;
use App\Models\Website;
use Illuminate\Console\Command;

/**
 * Sửa link bài đã đăng lên Magento bị dựng sai dạng "{base_url}/blog/post/{key}" (thừa "post") thành "{base_url}/blog/{key}".
 * Mặc định chỉ liệt kê, không ghi; thêm --force mới ghi. Không gọi CMS, không đăng lại bài.
 */
class FixMagentoPublicationUrlsCommand extends Command
{
    protected $signature = 'publications:fix-magento-urls
        {--dry-run : Chỉ liệt kê link sẽ đổi (mặc định)}
        {--force : Ghi link đã sửa vào lượt đăng}
        {--website= : Chỉ xử lý website có ID này}';

    protected $description = 'Sửa link bài đã đăng lên Magento bị thừa "/post" (mặc định chỉ liệt kê, --force mới ghi).';

    public function handle(): int
    {
        $sites = Website::query()->whereIn('cms_type', ['magento', 'magento2'])
            ->when($this->option('website'), fn ($q, $id) => $q->whereKey((int) $id))
            ->get();
        if ($sites->isEmpty()) {
            $this->warn('Không có website Magento nào để kiểm.');

            return self::SUCCESS;
        }

        $write = (bool) $this->option('force') && ! $this->option('dry-run');
        $rows = [];
        foreach ($sites as $site) {
            Publication::query()->where('website_id', $site->id)->where('status', 'published')
                ->where('published_url', 'like', '%/blog/post/%')->orderBy('id')
                ->get()->each(function (Publication $p) use ($site, $write, &$rows) {
                    $new = MagentoBlogUrl::legacyFix($p->published_url, $site);
                    if ($new === null || $new === $p->published_url) {
                        return;
                    }
                    $rows[] = [$p->id, $site->name, $p->published_url, $new];
                    if ($write) {
                        $old = $p->published_url;
                        $p->forceFill(['published_url' => $new])->save();
                        (new LogAuditSink)->write(['action' => 'publication_url_fixed', 'actor_role' => 'system', 'website_id' => $site->id,
                            'article_version_id' => $p->article_version_id, 'reason' => 'Sửa link Magento thừa /post',
                            'extra' => ['publication_id' => $p->id, 'from' => $old, 'to' => $new], 'at_utc' => now('UTC')->toIso8601String()]);
                    }
                });
        }

        if ($rows === []) {
            $this->info('Không có link nào cần sửa.');

            return self::SUCCESS;
        }

        $this->table(['Lượt đăng', 'Website', 'Link cũ', 'Link mới'], $rows);
        $this->info($write
            ? 'Đã sửa '.count($rows).' link.'
            : 'Có '.count($rows).' link sẽ được sửa. Chưa ghi gì; chạy lại với --force để ghi.');

        return self::SUCCESS;
    }
}
