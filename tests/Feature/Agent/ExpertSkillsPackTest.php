<?php

namespace Tests\Feature\Agent;

use App\Services\Agent\Roles\AgentProfiles;
use App\Services\Agent\Roles\ExpertTemplates;
use App\Services\Agent\Roles\RoleRegistry;
use App\Services\Agent\Roles\RoleTrainingImporter;
use App\Services\Ai\Playbook\PlaybookLoader;
use Tests\TestCase;

/** Gói kỹ năng chuyên gia theo vai trò (M09/ag-s28): playbook đọc được, bảng tiêu chí mẫu đọc ra được, không số liệu y khoa, mẫu hồ sơ đủ 14 vai trò. */
class ExpertSkillsPackTest extends TestCase
{
    private const NEW_SKILLS = [
        'researcher/medical-literature-reading', 'researcher/source-vetting', 'researcher/health-search-intent',
        'editor/evidence-wording', 'editor/claim-evidence-matrix',
        'writer/nutrition-communication', 'writer/senior-readability', 'writer/supplement-claims-awareness',
        'strategist/answer-first-structure', 'manager/ymyl-risk-triage',
        'designer/accessible-health-visual', 'designer/health-image-safety-qa',
        'monitor/ymyl-freshness-review', 'auditor/audit-ymyl-trust',
        'analyst/competitor-claims-reading', 'analyst/source-document-triage', 'planner/health-seasonal-planning',
    ];

    /** Ngưỡng, liều, khẩu phần: số kèm đơn vị y khoa. */
    private const MEDICAL_NUMBER = '/\d+([.,]\d+)?\s*(mg|mcg|µg|g\/dl|mmol|mmhg|kcal|ml|iu|%|gram|viên)\b/iu';

    public function test_moi_playbook_moi_doc_duoc_va_dung_vai_tro(): void
    {
        $loader = new PlaybookLoader(resource_path('playbooks'));
        foreach (self::NEW_SKILLS as $ref) {
            [$role, $key] = explode('/', $ref);
            $pb = $loader->findLatest($role, $key); // InvalidPlaybook sẽ ném nếu hỏng định dạng/lineage
            $this->assertNotNull($pb, "thiếu playbook {$ref}");
            $this->assertSame('draft', $pb->status, $ref);
            $this->assertNotSame('', trim($pb->lineage['adaptations']), $ref);
            $this->assertDoesNotMatchRegularExpression(self::MEDICAL_NUMBER, $pb->body, "{$ref} chứa số liệu y khoa");
        }
    }

    public function test_moi_vai_tro_co_du_ky_nang_moi_va_moi_ky_nang_khai_bao_deu_co_that(): void
    {
        $reg = new RoleRegistry;
        $declared = [];
        foreach ($reg->all() as $role) {
            foreach ($reg->skills($role) as $s) {
                $this->assertTrue($s['exists'], "{$role['key']} khai kỹ năng không có file: {$s['ref']}");
                $this->assertNull($s['error'], $s['ref']);
                $declared[$s['ref']] = true;
            }
        }
        foreach (self::NEW_SKILLS as $ref) {
            $this->assertArrayHasKey($ref, $declared, "{$ref} chưa được vai trò nào khai báo");
        }
    }

    public function test_playbook_hoc_tu_claude_seo_ghi_dung_phien_ban(): void
    {
        $loader = new PlaybookLoader(resource_path('playbooks'));
        foreach (['strategist/answer-first-structure' => 'claude-seo/seo-geo', 'manager/ymyl-risk-triage' => 'claude-seo/seo', 'auditor/audit-ymyl-trust' => 'claude-seo/seo', 'monitor/ymyl-freshness-review' => 'claude-seo/seo-drift', 'analyst/competitor-claims-reading' => 'claude-seo/seo-sxo'] as $ref => $src) {
            [$role, $key] = explode('/', $ref);
            $pb = $loader->findLatest($role, $key);
            $this->assertSame($src, $pb->lineage['source'], $ref);
            $this->assertSame('MIT', $pb->lineage['license'], $ref);
            $this->assertSame('2.5.0', $pb->lineage['source_version'], $ref);
        }
    }

    public function test_moi_vai_tro_co_bang_tieu_chi_mau_doc_duoc_khong_so_lieu_y_khoa(): void
    {
        foreach ((new RoleRegistry)->all() as $role) {
            $file = base_path('docs/seo-agent/mau-huan-luyen/'.str_replace('_', '-', $role['key']).'.csv');
            $this->assertFileExists($file, $role['key']);
            $text = (string) file_get_contents($file);
            $parsed = RoleTrainingImporter::parseTemplateCsv($text);
            $this->assertNotNull($parsed, "{$role['key']}: không đọc ra bảng tiêu chí");
            $this->assertGreaterThanOrEqual(5, count($parsed['criteria']), "{$role['key']} ít hơn 5 tiêu chí");
            foreach ($parsed['criteria'] as $c) {
                $this->assertNotSame('', trim($c['title']), $role['key']);
            }
            $this->assertDoesNotMatchRegularExpression(self::MEDICAL_NUMBER, $text, "{$role['key']} chứa số liệu y khoa");
        }
    }

    public function test_mau_ho_so_du_14_vai_tro_va_trong_gioi_han(): void
    {
        foreach ((new RoleRegistry)->all() as $role) {
            $t = ExpertTemplates::for($role['key']);
            $this->assertNotNull($t, "thiếu mẫu hồ sơ cho {$role['key']}");
            $this->assertLessThanOrEqual(AgentProfiles::PERSONA_MAX, mb_strlen($t['persona']), $role['key']);
            $this->assertLessThanOrEqual(AgentProfiles::INSTRUCTIONS_MAX, mb_strlen($t['instructions']), $role['key']);
            $this->assertDoesNotMatchRegularExpression(self::MEDICAL_NUMBER, str_replace('100%', '', $t['persona'].$t['instructions']), $role['key']);
        }
    }
}
