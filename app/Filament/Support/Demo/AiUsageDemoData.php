<?php

namespace App\Filament\Support\Demo;

use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\Website;
use App\Services\Ai\TokenCostCalculator;
use Carbon\CarbonImmutable;
use LogicException;

/**
 * Dữ liệu MẪU chỉ để xem thử màn Chi phí AI. Bản ghi mẫu luôn có tiền tố '[Mẫu AI] ' (riêng, không trùng tiền tố '[Mẫu] ' của các lớp mẫu khác) ở từ khóa bài và tên website;
 * purge() chỉ xóa đúng các bản ghi đó. Chi phí tính bằng TokenCostCalculator như pipeline thật.
 */
final class AiUsageDemoData
{
    public const PREFIX = '[Mẫu AI] ';

    private const MODELS = ['gemini-1.5-flash', 'gemini-1.5-flash', 'gemini-1.5-pro', 'gpt-4o-mini'];

    public static function hasSample(): bool
    {
        return Article::where('primary_keyword', 'like', self::PREFIX.'%')->exists();
    }

    /** @return int số phiên bản đã tạo (0 nếu đã có mẫu: chạy lại không nhân đôi) */
    public static function seed(): int
    {
        if (! DemoMode::enabled() || ! in_array(app()->environment(), ['local', 'testing'], true)) {
            throw new LogicException('Dữ liệu mẫu chỉ được tạo ở môi trường local hoặc testing.');
        }
        if (self::hasSample()) {
            return 0;
        }
        $sites = Website::orderBy('id')->limit(2)->get()->all();
        if ($sites === []) {
            $sites = [Website::create(['name' => self::PREFIX.'Website', 'cms_type' => 'wordpress', 'base_url' => 'https://mau.example', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'inactive'])];
        }
        $calc = new TokenCostCalculator;
        $now = CarbonImmutable::now('Asia/Ho_Chi_Minh');
        $count = 0;
        for ($i = 0; $i < 60; $i++) {
            $article = Article::create([
                'website_id' => $sites[$i % count($sites)]->id,
                'primary_keyword' => self::PREFIX.'từ khóa '.($i + 1),
            ]);
            $versions = $i % 7 === 0 ? 3 : ($i % 5 === 0 ? 2 : 1);
            $model = self::MODELS[$i % 4];
            for ($v = 1; $v <= $versions; $v++) {
                $at = $now->subDays((int) floor((59 - $i) * 34 / 59 + $v * 0.3))->setTime(7 + (($i * 3 + $v * 5) % 14), ($i * 11) % 60);
                $prompt = $at->lt($now->subDays(14)) ? 'v1.0' : 'v1.1';
                $in = 700 + ($i * 37 + $v * 53) % 400;
                $out = 2600 + ($i * 91 + $v * 17) % 1200;
                $missing = ($i * 7 + $v) % 23 === 0; // vài phiên bản chưa ghi chi phí (pipeline ghi 0 / 0)
                $row = new ArticleVersion([
                    'article_id' => $article->id, 'version' => $v,
                    'title' => self::PREFIX.'Bài mẫu số '.($i + 1).($v > 1 ? ' (sinh lại lần '.$v.')' : ''),
                    'slug' => 'mau-'.($i + 1).'-v'.$v, 'html_content' => '<p>Nội dung mẫu</p>',
                    'ai_provider' => str_starts_with($model, 'gpt') ? 'openai' : 'google', 'ai_model' => $model, 'prompt_version' => $prompt,
                    'tokens_used' => $missing ? 0 : $in + $out,
                    'cost_vnd' => $missing ? 0 : $calc->calculate($in, $out, $model)['total_cost_vnd'],
                ]);
                $row->created_at = $at;
                $row->updated_at = $at;
                $row->save();
                $count++;
            }
        }

        return $count;
    }

    /** Xóa đúng các bản ghi mẫu. @return int số bài mẫu đã xóa */
    public static function purge(): int
    {
        $ids = Article::where('primary_keyword', 'like', self::PREFIX.'%')->pluck('id');
        ArticleVersion::whereIn('article_id', $ids)->delete();
        Article::whereIn('id', $ids)->delete();
        Website::where('name', 'like', self::PREFIX.'%')->whereDoesntHave('articles')->delete();

        return $ids->count();
    }
}
