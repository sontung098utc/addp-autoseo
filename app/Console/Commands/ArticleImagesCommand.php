<?php

namespace App\Console\Commands;

use App\Models\Article;
use App\Services\Images\ArticleImageService;
use Illuminate\Console\Command;

class ArticleImagesCommand extends Command
{
    protected $signature = 'article:images {article : ID bài viết} {--regenerate : Làm mới ảnh AI đã có của phiên bản mới nhất}';

    protected $description = 'Tạo ảnh minh họa (AI tạo) cho phiên bản mới nhất của bài, có trần chi phí; không chạy lại nếu đã có ảnh AI';

    public function handle(): int
    {
        $article = Article::query()->find($this->argument('article'));
        $version = $article?->versions()->orderByDesc('version')->first();
        if (! $article || ! $version) {
            $this->error('Không thấy bài hoặc bài chưa có phiên bản.');

            return self::FAILURE;
        }
        $r = (new ArticleImageService)->generateFor($article, $version, ['regenerate' => (bool) $this->option('regenerate')]);
        $this->info("Đã tạo {$r['generated']} ảnh, chi phí {$r['cost_vnd']}đ.");
        if ($r['skipped']) {
            $this->warn($r['skipped']);
        }
        foreach ($r['errors'] ?? [] as $e) {
            $this->warn($e);
        }

        return self::SUCCESS;
    }
}
