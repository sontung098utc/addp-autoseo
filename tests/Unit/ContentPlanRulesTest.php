<?php

namespace Tests\Unit;

use App\Filament\Support\ContentPlan\MockTitleSuggester;
use App\Filament\Support\ContentPlan\PlanRules;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class ContentPlanRulesTest extends TestCase
{
    public function test_do_phu_chuan_hoa_hoa_thuong_khoang_trang(): void
    {
        $c = PlanRules::coverage(['A b', 'C d', 'E f'], ['  a   B ', 'c d']);
        $this->assertSame(['A b', 'C d'], $c['covered']);
        $this->assertSame(['E f'], $c['free']);
        $this->assertSame(67, $c['percent']);
        $this->assertSame(0, PlanRules::coverage([], ['x'])['percent']);
    }

    public function test_an_thit_tu_khoa(): void
    {
        $r = PlanRules::cannibalized([['id' => 1, 'keyword' => 'Học  bổng'], ['id' => 2, 'keyword' => 'học bổng'], ['id' => 3, 'keyword' => 'khác']]);
        $this->assertSame(['học bổng' => [1, 2]], $r);
    }

    public function test_gioi_han_ngay(): void
    {
        $this->assertNull(PlanRules::dayFullReason(2));
        $this->assertNotNull(PlanRules::dayFullReason(3));
        $this->assertNotNull(PlanRules::dayFullReason(2, 2));
    }

    public function test_doi_ngay_luat(): void
    {
        $now = CarbonImmutable::parse('2026-11-06 17:00', 'Asia/Ho_Chi_Minh');
        $d = fn ($s) => CarbonImmutable::parse($s, 'Asia/Ho_Chi_Minh');
        $this->assertNull(PlanRules::rescheduleBlockReason('planned', $d('2026-11-07'), 0, $now));
        $this->assertNull(PlanRules::rescheduleBlockReason('planned', $d('2026-11-06'), 2, $now));
        $this->assertStringContainsString('đã qua', (string) PlanRules::rescheduleBlockReason('planned', $d('2026-11-05'), 0, $now));
        $this->assertStringContainsString('đã đủ', (string) PlanRules::rescheduleBlockReason('planned', $d('2026-11-07'), 3, $now));
        foreach (['approved', 'scheduled', 'published'] as $s) {
            $this->assertStringContainsString('Lịch xuất bản', (string) PlanRules::rescheduleBlockReason($s, $d('2026-11-07'), 0, $now));
        }
        $this->assertNotNull(PlanRules::rescheduleBlockReason('drafting', $d('2026-11-07'), 0, $now));
    }

    public function test_slot_trong_va_goi_y_xac_dinh(): void
    {
        $this->assertSame('14:00', PlanRules::firstFreeSlot(['09:00']));
        $this->assertNull(PlanRules::firstFreeSlot(['09:00', '14:00', '19:00']));
        $in = [['keyword' => 'a b', 'intent' => 'commercial']];
        $this->assertSame((new MockTitleSuggester)->suggest($in), (new MockTitleSuggester)->suggest($in));
    }
}
