<?php

namespace Tests\Unit;

use App\Services\Ai\Playbook\PlaybookLoader;
use App\Services\Content\Quality\ContentQualityChecker;
use PHPUnit\Framework\TestCase;

/**
 * AG-S10: bộ kỹ năng theo vai trò học từ claude-seo phải có xuất xứ đúng, chỉ là nháp, và chỉ tham chiếu luật có thật.
 */
class PlaybookSkillsTest extends TestCase
{
    private const ROLES = ['manager', 'auditor', 'researcher', 'analyst', 'strategist', 'writer', 'editor', 'monitor'];

    /** playbook (ref không kèm phiên bản) => skill gốc chính của claude-seo. */
    private const PRIMARY_SKILL = [
        'manager/seo-orchestrator' => 'seo',
        'auditor/audit-onpage' => 'seo-audit',
        'auditor/audit-technical' => 'seo-technical',
        'researcher/keyword-cluster' => 'seo-cluster',
        'analyst/serp-gap' => 'seo-sxo',
        'strategist/content-plan' => 'seo-plan',
        'writer/article-draft' => 'seo-content',
        'editor/eeat-check' => 'seo-content',
        'monitor/drift-baseline' => 'seo-drift',
    ];

    private function loader(): PlaybookLoader
    {
        return new PlaybookLoader(dirname(__DIR__, 2).'/resources/playbooks');
    }

    public function test_moi_playbook_trong_repo_nap_duoc_va_du_tam_vai_tro(): void
    {
        $all = $this->loader()->all();
        $roles = array_unique(array_map(fn ($p) => $p->role, $all));

        foreach (self::ROLES as $role) {
            $this->assertContains($role, $roles, "Vai trò {$role} chưa có playbook nào");
        }
        foreach (array_keys(self::PRIMARY_SKILL) as $ref) {
            [$role, $key] = explode('/', $ref);
            $this->assertNotNull($this->loader()->find($role, $key, 1), "Thiếu playbook {$ref}");
        }
    }

    public function test_playbook_hoc_tu_claude_seo_ghi_dung_nguon_va_giay_phep(): void
    {
        foreach (self::PRIMARY_SKILL as $ref => $skill) {
            [$role, $key] = explode('/', $ref);
            $p = $this->loader()->find($role, $key, 1);

            $this->assertStringStartsWith('claude-seo/', $p->lineage['source'], $ref);
            $this->assertSame('claude-seo/'.$skill, $p->lineage['source'], $ref);
            $this->assertSame('MIT', $p->lineage['license'], $ref);
            $this->assertNotSame('', trim($p->lineage['adaptations']), "{$ref} phải nêu cụ thể đã bỏ/đổi gì");
        }
    }

    public function test_source_version_khop_skill_goc_neu_co_claude_seo_trong_moi_truong(): void
    {
        $base = getenv('CLAUDE_SEO_SKILLS_DIR') ?: '/home/zunp/addp-autoseo/claude-seo/skills';
        if (! is_dir($base)) {
            $this->markTestSkipped('Không có thư mục claude-seo trong môi trường này.');
        }

        foreach ($this->loader()->all() as $p) {
            if (! str_starts_with($p->lineage['source'], 'claude-seo/')) {
                continue;
            }
            $file = $base.'/'.substr($p->lineage['source'], strlen('claude-seo/')).'/SKILL.md';
            $this->assertFileExists($file, $p->ref());
            $this->assertSame(1, preg_match('/^\s*version:\s*"?([0-9][0-9.]*)"?\s*$/m', (string) file_get_contents($file), $m), $p->ref());
            $this->assertSame($m[1], $p->lineage['source_version'], "{$p->ref()} ghi sai phiên bản skill gốc");
        }
    }

    public function test_rubric_refs_la_ma_luat_that_va_khong_co_gia_tri_rong(): void
    {
        $known = array_map(fn ($r) => $r->code(), ContentQualityChecker::defaultRules());

        foreach ($this->loader()->all() as $p) {
            foreach ($p->rubricRefs as $ref) {
                $this->assertNotSame('', $ref, "{$p->ref()} có rubric_ref rỗng");
                $this->assertContains($ref, $known, "{$p->ref()} tham chiếu luật không tồn tại: {$ref}");
            }
        }
    }

    public function test_chua_qua_bo_mau_nen_khong_playbook_nao_active(): void
    {
        foreach ($this->loader()->all() as $p) {
            $this->assertNotSame('active', $p->status, "{$p->ref()} chưa qua bộ mẫu tiếng Việt (AG-K09) nên không được active");
            $this->assertFalse($p->isRunnable(), $p->ref());
        }
    }

    public function test_moi_playbook_moi_co_du_cac_muc_bat_buoc(): void
    {
        $sections = ['# Mục tiêu', '# Đầu vào', '# Các bước', '# Rào chắn', '# Điều kiện đạt', '# Nếu không đạt tự làm', '# Đầu ra'];

        foreach (array_keys(self::PRIMARY_SKILL) as $ref) {
            [$role, $key] = explode('/', $ref);
            $p = $this->loader()->find($role, $key, 1);
            foreach ($sections as $s) {
                $this->assertStringContainsString($s, $p->body, "{$ref} thiếu mục «{$s}»");
            }
            $this->assertStringContainsString('chỉ dẫn', $p->body, "{$ref} thiếu rào chắn chống prompt injection");
            $this->assertStringContainsString('```json', $p->body, "{$ref} thiếu schema JSON đầu ra");
        }
    }

    public function test_khong_dua_quy_tac_chua_xac_minh_nhu_su_that(): void
    {
        foreach ($this->loader()->all() as $p) {
            // mật độ từ khóa dạng phần trăm và điểm Flesch/E-E-A-T số không được xuất hiện như một yêu cầu
            $this->assertDoesNotMatchRegularExpression('/mật độ[^.\n]{0,40}\d+\s*%/iu', $p->body, "{$p->ref()}: mật độ % không có căn cứ Google");
            $this->assertStringNotContainsString('Flesch Reading Ease', str_replace('điểm Flesch', '', $p->body), $p->ref());
        }
    }
}
