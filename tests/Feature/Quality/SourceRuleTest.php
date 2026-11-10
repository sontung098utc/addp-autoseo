<?php

namespace Tests\Feature\Quality;

use App\Models\Article;
use App\Models\Brain;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeSource;
use App\Models\User;
use App\Models\Website;
use App\Services\Brain\BrainSourceRegistry;
use App\Services\Content\Quality\ContentQualityChecker;
use App\Services\Content\Quality\Rules\EvidenceRule;
use App\Services\Content\Quality\Rules\SourceRule;
use App\Services\Knowledge\KnowledgeDedupe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Unit\QualityFixtures;

/** SRC01: nguồn AI nêu phải khớp nguồn có thật. Tất định, không AI/mạng. */
class SourceRuleTest extends TestCase
{
    use RefreshDatabase;

    private int $ver = 0;

    private function world(): array
    {
        $w = Website::create(['name' => 'Cửa hàng Mẫu', 'cms_type' => 'wordpress', 'base_url' => 'https://'.uniqid().'.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $b = Brain::create(['name' => 'Y khoa', 'slug' => 'yk-'.uniqid(), 'status' => 'active', 'settings' => ['ai_reliance' => 'must_cite', 'risk_class' => 'high']]);
        $b->websites()->attach($w->id, ['priority' => 10]);
        $a = Article::create(['website_id' => $w->id, 'primary_keyword' => 'học bổng toàn phần', 'workflow_status' => 'planned']);

        return [$w, $b, $a];
    }

    private function run_(Article $a, array $cited, ?array $rules = null, string $extra = '')
    {
        $v = QualityFixtures::version($a, ++$this->ver, 'needs_review', ['html_content' => QualityFixtures::goodHtml().$extra, 'sources_cited' => $cited]);

        return (new ContentQualityChecker($rules ?? [new SourceRule]))->check($v, $a);
    }

    private function src01($report): array
    {
        return array_values(array_filter($report->results, fn ($r) => $r->code === 'SRC01'));
    }

    public function test_nguon_khop_ten_mien_kho_khong_co(): void
    {
        [$w, , $a] = $this->world();
        $s = KnowledgeSource::create(['website_id' => $w->id, 'kind' => 'url', 'title' => 'Tài liệu giá', 'url' => 'https://docs.congty.vn/gia', 'status' => 'ready']);
        KnowledgeItem::create(['website_id' => $w->id, 'source_id' => $s->id, 'type' => 'price', 'title' => 'Giá', 'body' => 'Giá 1', 'status' => 'approved', 'version' => 1, 'content_hash' => KnowledgeDedupe::hash('Giá', 'Giá 1')]);
        $r = $this->src01($this->run_($a, ['https://DOCS.congty.vn/gia/2026', 'Tài liệu giá']));
        $this->assertCount(1, $r);
        $this->assertTrue($r[0]->passed);
    }

    public function test_nguon_khop_so_nguon_khong_co(): void
    {
        [, $b, $a] = $this->world();
        (new BrainSourceRegistry)->add($b, 'Tổ chức Y tế Thế giới', 'who.int', '1', null, User::create(['name' => 'a', 'email' => 'a@e.vn', 'password' => 'secret123', 'role' => 'admin', 'is_active' => true]));
        $r = $this->src01($this->run_($a, ['tổ chức y tế thế giới', 'https://www.who.int/news']));
        $this->assertTrue($r[0]->passed);
    }

    public function test_nguon_bia_co_p1_chua_kiem_va_khong_chan(): void
    {
        [, , $a] = $this->world();
        $rep = $this->run_($a, ['Viện Nghiên cứu Không Có 2099', 'https://khong-co-that.example/x']);
        $r = $this->src01($rep);
        $this->assertFalse($r[0]->passed);
        $this->assertSame('P1', $r[0]->severity);
        $this->assertStringContainsString('chưa kiểm', $r[0]->message);
        $this->assertNotContains('SRC01', $rep->blockingCodes());
    }

    public function test_khong_sources_cited_khong_co(): void
    {
        [, , $a] = $this->world();
        $this->assertSame([], $this->src01($this->run_($a, [])));
    }

    public function test_nguon_bi_chan_khong_nhan_doi_co(): void
    {
        [, $b, $a] = $this->world();
        (new BrainSourceRegistry)->add($b, 'Blog Xấu', 'xau.vn', 'blocked', null, User::create(['name' => 'a', 'email' => 'a@e.vn', 'password' => 'secret123', 'role' => 'admin', 'is_active' => true]));
        $rep = $this->run_($a, ['https://xau.vn/bai'], [new SourceRule, new EvidenceRule]);
        foreach ($this->src01($rep) as $x) {
            $this->assertTrue($x->passed, 'nguồn chặn do EVID01 lo, SRC01 không nhân đôi cờ');
        }
        $this->assertContains('EVID01', $rep->blockingCodes());
    }
}
