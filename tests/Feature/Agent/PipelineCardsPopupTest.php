<?php

namespace Tests\Feature\Agent;

use App\Models\AgentRun;
use App\Models\User;
use App\Models\Website;
use App\Services\Agent\Pipeline\JobTypeRegistry;
use App\Services\Agent\Pipeline\PipelineVisual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Tab "Các luồng": lưới thẻ lớn (hình, tiêu đề, mô tả, chỉ số thật) và popup luồng đầy đủ. */
class PipelineCardsPopupTest extends TestCase
{
    use RefreshDatabase;

    private function html(): string
    {
        $u = User::create(['name' => 'a', 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => 'approver', 'is_active' => true]);
        $this->actingAs($u);

        return $this->get('/admin/doi-ai/pipeline')->assertOk()->getContent();
    }

    private function mkRun(string $status, ?string $type): AgentRun
    {
        $w = Website::query()->first() ?? Website::create(['name' => 'Site A', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);

        return AgentRun::create(['website_id' => $w->id, 'trigger' => 'manual', 'status' => $status, 'trust_tier' => 'T1', 'ai_mode' => 'mock', 'job_type' => $type, 'started_at' => now()]);
    }

    private function card(string $html, string $id): string
    {
        $a = strpos($html, 'data-card="'.$id.'"');
        $this->assertNotFalse($a, $id);

        return substr($html, $a, strpos($html, '</button>', $a) - $a);
    }

    private function popup(string $html, string $id): string
    {
        $a = strpos($html, 'data-flow="'.$id.'"');

        return substr($html, $a, strpos($html, '</section>', $a) - $a);
    }

    public function test_bay_the_luong_dung_loai_co_hinh_minh_hoa_tieu_de_va_mo_ta(): void
    {
        $html = $this->html();
        $ids = array_column(JobTypeRegistry::all(), 'id');
        $this->assertSame(['market_research', 'site_audit', 'content_plan', 'article_write', 'article_refresh', 'brain_build', 'measure'], array_values(array_intersect(['market_research', 'site_audit', 'content_plan', 'article_write', 'article_refresh', 'brain_build', 'measure'], $ids)));
        preg_match_all('/data-card="([^"]+)"/', $html, $m);
        $this->assertSame($ids, $m[1]);
        foreach (JobTypeRegistry::all() as $j) {
            $card = $this->card($html, $j['id']);
            $this->assertStringContainsString('data-illustration="'.$j['id'].'"', $card);
            $this->assertStringContainsString('<svg', $card);
            $this->assertStringContainsString($j['title'], $card);
            $this->assertStringContainsString('pc__plain', $card);
            $this->assertStringContainsString('data-steps="'.count($j['steps']).'"', $card);
        }
        $this->assertStringNotContainsString('pv-chipbtn', $html);
    }

    public function test_the_hien_chi_so_that_dem_tu_phien_chay(): void
    {
        $mk = function (string $status, ?string $type, $at = null) {
            $r = $this->mkRun($status, $type);
            $at && $r->forceFill(['created_at' => $at])->saveQuietly();

            return $r;
        };
        $mk('running', 'site_audit');
        $mk('ready_for_review', 'site_audit');
        $mk('failed', 'site_audit');
        $mk('ready_for_review', 'site_audit', now()->subDays(20));
        $mk('ready_for_review', 'article_write');

        $s = PipelineVisual::cardStats();
        $this->assertSame(1, $s['site_audit']['running']);
        $this->assertSame(3, $s['site_audit']['week']);
        $this->assertSame(1, $s['site_audit']['failed']);
        $this->assertSame(50, $s['site_audit']['rate']);
        $this->assertSame(1, $s['article_write']['week']);
        $this->assertNull($s['measure']['rate'], 'không có phiên thì không bịa tỷ lệ');
        $this->assertSame(0, $s['measure']['week']);

        $html = $this->html();
        $card = $this->card($html, 'site_audit');
        $this->assertStringContainsString('data-running="1"', $card);
        $this->assertStringContainsString('data-week="3"', $card);
        $this->assertStringContainsString('pc__pulse', $card);
        $this->assertStringNotContainsString('pc__pulse', $this->card($html, 'measure'));
        $this->assertStringNotContainsString('Chưa có dữ liệu xong/lỗi', $this->card($html, 'measure'));
        $this->assertStringNotContainsString('0</b> đang chạy', $this->card($html, 'measure'));
    }

    public function test_the_co_chip_can_thiep_khi_luong_co_diem_dung_cua_nguoi(): void
    {
        $html = $this->html();
        $this->assertStringContainsString('data-human', $this->card($html, 'article_write'));
    }

    public function test_popup_co_du_buoc_dung_thu_tu_va_chi_tiet_tung_buoc(): void
    {
        $html = $this->html();
        foreach (JobTypeRegistry::all() as $j) {
            $p = $this->popup($html, $j['id']);
            preg_match_all('/data-step="([^"]+)"/', $p, $m);
            $this->assertSame(array_column($j['steps'], 'id'), $m[1], $j['id']);
            preg_match_all('/data-detail-step="([^"]+)"/', $p, $d);
            $this->assertSame($m[1], $d[1], 'mỗi bước có một khung chi tiết');
            foreach (['Vì sao có bước này', 'Dữ liệu vào', 'Dữ liệu ra', 'Nhân sự phụ trách', 'Luật / tiêu chí áp dụng'] as $t) {
                $this->assertStringContainsString($t, $p, $j['id']);
            }
            $this->assertStringContainsString('Đầu vào của cả luồng', $p);
            $this->assertStringContainsString('Kết quả của cả luồng', $p);
            $this->assertStringNotContainsString('Chưa có phiên nào của luồng này', $p);
            $this->assertStringContainsString('data-testid="runs-of-'.$j['id'].'"', $p);
            $this->assertStringContainsString('role="dialog"', $html);
        }
        $aw = $this->popup($html, 'article_write');
        $this->assertStringContainsString('fl-kind', $aw);
        $this->assertStringContainsString('Người quyết định', $aw);
        $this->assertStringContainsString('Tùy điều kiện', $aw);
        $this->assertStringContainsString('Kết quả của bước «Chấm điểm»', str_replace('Chuyển cho bước', 'Kết quả của bước', 'Chuyển cho bước «Chấm điểm»'));
    }

    public function test_phien_gan_day_chi_gom_phien_cua_luong_do_va_nut_xem_phien_loc_theo_loai(): void
    {
        $r = $this->mkRun('failed', 'measure');
        $other = $this->mkRun('ready_for_review', 'site_audit');
        $html = $this->html();
        $this->assertStringContainsString('data-recent-run="'.$r->id.'"', $this->popup($html, 'measure'));
        $this->assertStringNotContainsString('data-recent-run="'.$other->id.'"', $this->popup($html, 'measure'));
        $this->assertStringContainsString('tableFilters%5Bjob_type%5D%5Bvalue%5D=measure', $this->popup($html, 'measure'));
    }
}
