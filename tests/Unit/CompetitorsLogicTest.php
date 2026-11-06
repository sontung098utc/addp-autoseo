<?php

namespace Tests\Unit;

use App\Filament\Support\Competitors\ClusterSuggester;
use App\Filament\Support\Competitors\CompetitorDomain;
use App\Filament\Support\Competitors\GapCalculator;
use App\Filament\Support\Competitors\PriorityScorer;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CompetitorsLogicTest extends TestCase
{
    public function test_domain_normalizes_scheme_path_www_case(): void
    {
        $this->assertSame('doithu.vn', CompetitorDomain::validate('  HTTPS://www.DoiThu.vn/bai-viet?x=1#a ', []));
        $this->assertSame('a.doithu.com.vn', CompetitorDomain::validate('a.doithu.com.vn', []));
    }

    #[DataProvider('bad')]
    public function test_domain_rejects(string $input, array $existing = [], ?string $own = null): void
    {
        $this->expectException(InvalidArgumentException::class);
        CompetitorDomain::validate($input, $existing, $own);
    }

    public static function bad(): array
    {
        return [
            'rỗng' => [''],
            'ipv4' => ['8.8.8.8'], 'metadata' => ['http://169.254.169.254/latest'], 'loopback' => ['127.0.0.1'],
            'ipv6' => ['[::1]'], 'localhost' => ['localhost'], 'local' => ['nas.local'], 'internal' => ['db.internal'],
            'test' => ['site.test'], 'ký tự lạ' => ['doi thu.vn'], 'dấu' => ['đốithủ.vn'], 'ký tự đặc biệt' => ['doi_thu$.vn'],
            'userinfo' => ['user:pw@doithu.vn'], 'cổng' => ['doithu.vn:8080'], 'một nhãn' => ['doithu'],
            'trùng' => ['doithu.vn', ['doithu.vn']],
            'trùng khác dạng' => ['https://www.DoiThu.vn/x', ['doithu.vn']],
            'của mình' => ['blog.company.vn', [], 'https://blog.company.vn/'],
            'của mình có www' => ['https://www.blog.company.vn', [], 'https://blog.company.vn'],
            'quá 10' => ['moi.vn', array_map(fn ($i) => "d$i.vn", range(1, 10))],
        ];
    }

    public function test_domain_allows_tenth(): void
    {
        $this->assertSame('moi.vn', CompetitorDomain::validate('moi.vn', array_map(fn ($i) => "d$i.vn", range(1, 9))));
    }

    public function test_scorer_hand_calculated(): void
    {
        // V = log10(100)/log10(1000) = 2/3 ; D = 0,6 ; O = 1/2 -> 100*(0,3333+0,18+0,1) = 61,33
        $s = PriorityScorer::score(99, 40, 1, 2, 999);
        $this->assertSame(61, $s['score']);
        $this->assertEqualsWithDelta(33.33, $s['pts_volume'], 0.01);
        $this->assertEqualsWithDelta(18.0, $s['pts_difficulty'], 0.001);
        $this->assertEqualsWithDelta(10.0, $s['pts_overlap'], 0.001);
        $this->assertStringContainsString('Tổng 61,3', $s['reason']);
    }

    public function test_scorer_boundaries(): void
    {
        $this->assertSame(100, PriorityScorer::score(5000, 0, 3, 3, 5000)['score']);
        $this->assertSame(0, PriorityScorer::score(0, 100, 0, 3, 5000)['score']);
        $this->assertSame(0, PriorityScorer::score(0, 100, 1, 0, 0)['score']); // không đối thủ, volume lớn nhất 0: không chia 0
        $this->assertSame(30, PriorityScorer::score(0, 0, 0, 3, 5000)['score']); // chỉ độ khó dễ nhất
        $this->assertSame(20, PriorityScorer::score(0, 100, 1, 1, 5000)['score']); // một đối thủ, chồng lấn đủ
        $this->assertSame(PriorityScorer::score(10, 100, 0, 1, 10)['score'], PriorityScorer::score(10, 400, 0, 1, 10)['score']);
    }

    public function test_gap_marks_existing_case_and_space_insensitive_and_excludes_from_suggestions(): void
    {
        $rows = [
            ['keyword' => 'Học  Bổng  Du Học', 'volume' => 1000, 'difficulty' => 30, 'ranks' => ['a.vn' => 3]],
            ['keyword' => 'ngành marketing', 'volume' => 500, 'difficulty' => 20, 'ranks' => ['a.vn' => 1, 'b.vn' => 2]],
            ['keyword' => 'không hạng', 'volume' => 900, 'difficulty' => 20, 'ranks' => []],
        ];
        $existing = ['học bổng du học' => ['id' => 7, 'keyword' => 'học bổng du học', 'status' => 'planned', 'cluster' => null]];
        $gaps = GapCalculator::calculate($rows, $existing, 2);

        $this->assertCount(2, $gaps); // dòng không đối thủ nào xếp hạng bị loại
        $this->assertSame('ngành marketing', $gaps[0]['keyword']);
        $this->assertNull($gaps[0]['existing']);
        $this->assertNotNull($gaps[0]['score']);
        $this->assertSame(7, $gaps[1]['existing']['id']);
        $this->assertNull($gaps[1]['score']);
        foreach (ClusterSuggester::suggest($gaps) as $c) {
            $this->assertNotContains('Học Bổng Du Học', $c['keywords']);
        }
    }

    public function test_gap_merges_duplicate_keywords(): void
    {
        $gaps = GapCalculator::calculate([
            ['keyword' => 'Từ khóa', 'volume' => 10, 'difficulty' => 5, 'ranks' => ['a.vn' => 1]],
            ['keyword' => 'từ  khóa', 'volume' => 20, 'difficulty' => 5, 'ranks' => ['b.vn' => 4]],
        ], [], 2);
        $this->assertCount(1, $gaps);
        $this->assertSame(['a.vn' => 1, 'b.vn' => 4], $gaps[0]['ranks']);
    }

    public function test_cluster_groups_by_shared_token_deterministically(): void
    {
        $mk = fn ($k, $v) => ['keyword' => $k, 'norm' => $k, 'volume' => $v, 'existing' => null];
        $c = ClusterSuggester::suggest([$mk('học bổng du học', 100), $mk('lộ trình học bổng', 50), $mk('test chọn ngành', 70)]);
        $this->assertCount(1, $c);
        $this->assertSame(150, $c[0]['volume']);
        $this->assertSame($c, ClusterSuggester::suggest([$mk('học bổng du học', 100), $mk('lộ trình học bổng', 50), $mk('test chọn ngành', 70)]));
    }
}
