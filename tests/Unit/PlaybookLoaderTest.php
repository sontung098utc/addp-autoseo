<?php

namespace Tests\Unit;

use App\Services\Ai\Playbook\InvalidPlaybook;
use App\Services\Ai\Playbook\PlaybookLoader;
use PHPUnit\Framework\TestCase;

/**
 * AG-K02: playbook phải có xuất xứ, phiên bản bất biến và đúng định dạng; sai thì bị TỪ CHỐI, không nạp nửa chừng.
 */
class PlaybookLoaderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/playbooks_'.bin2hex(random_bytes(6));
        mkdir($this->dir.'/auditor', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*/*') ?: [] as $f) {
            @unlink($f);
        }
        foreach (glob($this->dir.'/*') ?: [] as $d) {
            @rmdir($d);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function valid(array $over = [], string $body = "# Mục tiêu\nNội dung."): string
    {
        $m = $over + [
            'key' => 'audit-summary', 'version' => '1', 'role' => 'auditor', 'title' => 'Tóm tắt', 'status' => 'draft',
            'model_tier' => 'cheap', 'reviewed_at' => '2026-11-07',
        ];
        $head = "key: {$m['key']}\nversion: {$m['version']}\nrole: {$m['role']}\ntitle: {$m['title']}\nstatus: {$m['status']}\nmodel_tier: {$m['model_tier']}\n"
            ."rubric_refs:\n  - LEN01\n  - HEAD01\n"
            .($m['lineage'] ?? "lineage:\n  source: claude-seo/seo-audit\n  source_version: 2.5.0\n  license: MIT\n  adaptations: Chuyển ngữ tiếng Việt\n")
            ."reviewed_at: {$m['reviewed_at']}\n";

        return "---\n{$head}---\n{$body}\n";
    }

    private function put(string $content, string $role = 'auditor', string $name = 'audit-summary.v1.md'): string
    {
        if (! is_dir($this->dir.'/'.$role)) {
            mkdir($this->dir.'/'.$role, 0777, true);
        }
        $path = $this->dir.'/'.$role.'/'.$name;
        file_put_contents($path, $content);

        return $path;
    }

    public function test_nap_playbook_hop_le_day_du_truong(): void
    {
        $p = (new PlaybookLoader($this->dir))->load($this->put($this->valid()));

        $this->assertSame('auditor/audit-summary@1', $p->ref());
        $this->assertSame(['LEN01', 'HEAD01'], $p->rubricRefs);
        $this->assertSame('claude-seo/seo-audit', $p->lineage['source']);
        $this->assertSame('MIT', $p->lineage['license']);
        $this->assertStringContainsString('# Mục tiêu', $p->body);
        $this->assertSame(64, strlen($p->checksum));
        $this->assertFalse($p->isRunnable(), 'draft không được chạy tự động');
    }

    public function test_thieu_xuat_xu_bi_tu_choi(): void
    {
        $this->expectException(InvalidPlaybook::class);
        $this->expectExceptionMessage('lineage');
        (new PlaybookLoader($this->dir))->load($this->put($this->valid(['lineage' => "lineage:\n  source: claude-seo/seo-audit\n  source_version: 2.5.0\n"])));
    }

    public function test_khong_co_lineage_bi_tu_choi(): void
    {
        $this->expectException(InvalidPlaybook::class);
        $content = preg_replace('/lineage:.*?reviewed_at/s', 'reviewed_at', $this->valid());
        (new PlaybookLoader($this->dir))->load($this->put($content));
    }

    public function test_ten_file_phai_khop_key_version_va_vai_tro(): void
    {
        $this->expectException(InvalidPlaybook::class);
        $this->expectExceptionMessage('tên file/thư mục');
        (new PlaybookLoader($this->dir))->load($this->put($this->valid(), 'auditor', 'sai-ten.v1.md'));
    }

    public function test_thu_muc_vai_tro_phai_khop(): void
    {
        $this->expectException(InvalidPlaybook::class);
        (new PlaybookLoader($this->dir))->load($this->put($this->valid(['role' => 'writer']), 'auditor'));
    }

    public function test_gia_tri_ngoai_danh_sach_bi_tu_choi(): void
    {
        foreach ([['status' => 'live'], ['model_tier' => 'huge'], ['version' => '0'], ['version' => 'abc'], ['reviewed_at' => '07/11/2026']] as $bad) {
            try {
                (new PlaybookLoader($this->dir))->load($this->put($this->valid($bad), 'auditor', 'audit-summary.v'.($bad['version'] ?? '1').'.md'));
                $this->fail('Phải từ chối: '.json_encode($bad));
            } catch (InvalidPlaybook) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_thieu_frontmatter_hoac_noi_dung_rong_bi_tu_choi(): void
    {
        foreach (["Không có phần đầu\n", $this->valid([], '')] as $i => $content) {
            try {
                (new PlaybookLoader($this->dir))->load($this->put($content, 'auditor', 'audit-summary.v1.md'));
                $this->fail("Mẫu {$i} phải bị từ chối");
            } catch (InvalidPlaybook) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_checksum_doi_khi_noi_dung_doi(): void
    {
        $l = new PlaybookLoader($this->dir);
        $a = $l->load($this->put($this->valid()));
        $b = $l->load($this->put($this->valid([], "# Mục tiêu\nNội dung ĐÃ SỬA.")));

        $this->assertNotSame($a->checksum, $b->checksum);
    }

    public function test_chi_playbook_active_moi_chay_duoc_va_lay_ban_cao_nhat(): void
    {
        $this->put($this->valid(['status' => 'active']), 'auditor', 'audit-summary.v1.md');
        $this->put($this->valid(['status' => 'active', 'version' => '2']), 'auditor', 'audit-summary.v2.md');
        $this->put($this->valid(['status' => 'draft', 'version' => '3']), 'auditor', 'audit-summary.v3.md');
        $l = new PlaybookLoader($this->dir);

        $this->assertSame(2, $l->latestActive('auditor', 'audit-summary')->version);
        $this->assertSame(3, $l->find('auditor', 'audit-summary')->version);
        $this->assertSame(1, $l->find('auditor', 'audit-summary', 1)->version);
        $this->assertNull($l->latestActive('auditor', 'khong-co'));
    }

    public function test_mot_file_hong_lam_ca_lan_nap_that_bai_khong_bo_sot_am_tham(): void
    {
        $this->put($this->valid());
        $this->put("rác\n", 'auditor', 'hong.v1.md');

        $this->expectException(InvalidPlaybook::class);
        (new PlaybookLoader($this->dir))->all();
    }

    public function test_cac_playbook_that_trong_repo_deu_hop_le_va_co_ghi_cong(): void
    {
        $loader = new PlaybookLoader(dirname(__DIR__, 2).'/resources/playbooks');
        $all = $loader->all();

        $this->assertNotEmpty($all);
        foreach ($all as $p) {
            $this->assertNotSame('', $p->lineage['source'], $p->ref());
            // playbook học từ claude-seo phải ghi đúng giấy phép MIT và có file NOTICE
            if (str_starts_with($p->lineage['source'], 'claude-seo/')) {
                $this->assertSame('MIT', $p->lineage['license'], $p->ref());
            }
            $this->assertFalse($p->isRunnable() && $p->status !== 'active');
        }
        $this->assertFileExists(dirname(__DIR__, 2).'/resources/playbooks/NOTICE.md');
    }

    public function test_playbook_mau_chua_qua_bo_mau_nen_chi_la_draft(): void
    {
        $p = (new PlaybookLoader(dirname(__DIR__, 2).'/resources/playbooks'))->find('auditor', 'audit-summary', 1);

        $this->assertSame('draft', $p->status, 'Playbook chưa qua bộ mẫu tiếng Việt (AG-K09) thì không được active');
        $this->assertNull((new PlaybookLoader(dirname(__DIR__, 2).'/resources/playbooks'))->latestActive('auditor', 'audit-summary'));
    }

    public function test_playbook_brief_va_editor_la_nhap_va_tham_chieu_luat_co_that(): void
    {
        $loader = new PlaybookLoader(dirname(__DIR__, 2).'/resources/playbooks');
        $known = array_map(fn ($r) => $r->code(), \App\Services\Content\Quality\ContentQualityChecker::defaultRules());

        foreach ([['brief', 'content-brief'], ['editor', 'qa-review']] as [$role, $key]) {
            $p = $loader->find($role, $key, 1);
            $this->assertSame('draft', $p->status, "{$role}/{$key} chưa qua AG-K09 thật nên chỉ là nháp");
            foreach ($p->rubricRefs as $ref) {
                $this->assertContains($ref, $known, "{$role}/{$key} tham chiếu luật không tồn tại: {$ref}");
            }
        }
    }
}
