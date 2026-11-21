<?php

namespace Tests\Feature\Planning;

use App\Filament\Clusters\AgentTeam\Pages\AgentRoles;
use App\Filament\Pages\ContentPlanPage;
use App\Models\User;
use App\Models\Website;
use App\Services\Agent\Roles\RoleTrainingImporter;
use App\Services\Agent\Roles\RoleTrainingTemplate;
use App\Services\Planning\Import\PlanFileHeuristics;
use App\Services\Planning\Import\PlanFileImporter;
use App\Services\Planning\Import\PlanFileTemplate;
use App\Services\Planning\Import\SheetReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** File mẫu tải về: kế hoạch (xlsx/csv) và huấn luyện (csv/md). Vòng tròn: tải mẫu → đọc lại → hiểu đúng. */
class FileTemplatesTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'admin'): User
    {
        return User::create(['name' => $role, 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => $role, 'is_active' => true]);
    }

    private function tmp(string $bytes, string $ext): string
    {
        $p = tempnam(sys_get_temp_dir(), 'tpl_').'.'.$ext;
        file_put_contents($p, $bytes);

        return $p;
    }

    public function test_mau_ke_hoach_xlsx_va_csv_doc_lai_moi_cot_anh_xa_dung_truong(): void
    {
        $expected = ['primary_keyword', 'secondary_keywords', 'search_intent', 'topic_cluster', 'title', 'guidance', 'search_volume', 'planned_date', 'priority', 'campaign', 'campaign_description'];
        foreach (['xlsx' => PlanFileTemplate::xlsx(), 'csv' => PlanFileTemplate::csv()] as $ext => $bytes) {
            $path = $this->tmp($bytes, $ext);
            $sheets = (new SheetReader)->read($path, $ext);
            if ($ext === 'xlsx') {
                $this->assertSame('Kế hoạch', $sheets[0]['name']);
            }
            $map = PlanFileHeuristics::mapByHeader($sheets[0], 1);
            $this->assertSame($expected, array_values($map), "{$ext}: mọi cột của mẫu phải được nhận đúng trường");
            $this->assertCount(3, $sheets[0]['rows'], 'tiêu đề + 2 dòng ví dụ');
            if ($ext === 'xlsx') {
                $this->assertSame('Hướng dẫn', $sheets[1]['name']);
                $this->assertGreaterThanOrEqual(12, count($sheets[1]['rows']));
            }
            @unlink($path);
        }
    }

    public function test_dong_vi_du_quen_xoa_khong_tao_bai_mau_con_dong_that_van_vao(): void
    {
        $site = Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://s.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $sheet = (new SheetReader)->read($this->tmp(PlanFileTemplate::csv(), 'csv'), 'csv')[0];
        $rows = $sheet['rows'];
        $rows[] = ['chế độ ăn cho người gút', '', 'thông tin', '', '', '', '', '', '', '', ''];
        $map = PlanFileHeuristics::mapByHeader(['rows' => $rows], 1);
        $r = (new PlanFileImporter)->build($site, $rows, $map, 1);
        $kws = array_column($r['items'], 'keyword');
        $this->assertSame(['chế độ ăn cho người gút'], $kws, 'hai dòng ví dụ bị bỏ qua, dòng thật vẫn vào');
    }

    public function test_tai_mau_ke_hoach_tren_trang_ke_hoach(): void
    {
        $this->actingAs($this->user('approver'));
        Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://s.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        Livewire::test(ContentPlanPage::class)->assertSeeHtml('data-testid="plan-template-banner"')->assertSee('Tải file mẫu về điền cho chuẩn'); // đã thấy ngay ở khung giới thiệu, trước khi chọn file
        $c = Livewire::test(ContentPlanPage::class)->call('openImport');
        $c->assertSee('Tải file mẫu về điền cho chuẩn')->assertSeeHtml('data-testid="plan-template-xlsx"');
        $c->call('downloadPlanTemplate', 'xlsx')->assertFileDownloaded('mau-ke-hoach-noi-dung.xlsx');
        Livewire::test(ContentPlanPage::class)->call('openImport')->call('downloadPlanTemplate', 'csv')->assertFileDownloaded('mau-ke-hoach-noi-dung.csv');
    }

    public function test_mau_huan_luyen_csv_dien_xong_doc_truc_tiep_khong_can_ai_va_bo_dong_vi_du(): void
    {
        $csv = RoleTrainingTemplate::csv('writer');
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv, 'UTF-8 có BOM để Excel mở đúng tiếng Việt');
        $this->assertStringContainsString('Quy tắc cụ thể', strtok($csv, "\n"));
        // Mẫu chưa điền: toàn dòng ví dụ → không có mục nào.
        $this->assertSame([], RoleTrainingImporter::parseTemplateCsv($csv)['criteria']);
        $filled = $csv."tiêu chí,Có mục đi khám,Mỗi bài bệnh lý có mục khi nào cần đi khám,An toàn người đọc,Bắt buộc\n"
            ."tiêu chí,Giọng văn,Viết câu ngắn và giải nghĩa thuật ngữ,,Nên\nbài học,,Luôn nêu tên cơ quan khi trích khuyến cáo,,\n";
        $d = RoleTrainingImporter::parseTemplateCsv($filled);
        $this->assertCount(2, $d['criteria']);
        $this->assertSame('bat_buoc', $d['criteria'][0]['severity']);
        $this->assertSame(['Luôn nêu tên cơ quan khi trích khuyến cáo'], $d['lessons']);

        // analyze() đọc trực tiếp, KHÔNG gọi AI (resolver ném lỗi nếu bị gọi).
        $imp = new RoleTrainingImporter(fn () => throw new \RuntimeException('AI không được gọi với form mẫu'));
        $p = $imp->analyze('writer', ltrim($filled, "\xEF\xBB\xBF"), 'mau.csv');
        $this->assertTrue($p['template']);
        $this->assertSame(['criterion' => 2, 'lesson' => 1], array_count_values(array_column($p['items'], 'kind')));
        $this->assertStringContainsString('không dùng AI', (string) $p['model']);
        $this->assertSame(0.0, $p['cost']);
    }

    public function test_mau_huan_luyen_csv_dau_cham_phay_kieu_excel_viet_nam(): void
    {
        $text = "Loại;Tiêu chí;Quy tắc cụ thể;Vì sao;Mức\ntiêu chí;Nguồn;Luôn dẫn nguồn đã duyệt cho mọi số liệu;An toàn;Bắt buộc\n";
        $d = RoleTrainingImporter::parseTemplateCsv($text);
        $this->assertCount(1, $d['criteria']);
        $this->assertSame('Nguồn', $d['criteria'][0]['title']);
        $this->assertNull(RoleTrainingImporter::parseTemplateCsv("a,b\n1,2"), 'tệp CSV khác không phải form mẫu thì để AI đọc');
    }

    public function test_mau_huan_luyen_markdown_co_khung_va_dung_vai_tro(): void
    {
        $md = RoleTrainingTemplate::markdown('editor');
        foreach (['## Tiêu chí', '## Bài học ngắn', '## Quy trình', '## Kiến thức nền', 'Huấn luyện cho vai trò: Biên tập'] as $s) {
            $this->assertStringContainsString($s, $md);
        }
    }

    public function test_tai_mau_huan_luyen_tren_giao_dien_va_banner_noi_bat(): void
    {
        $this->actingAs($this->user());
        $c = Livewire::test(AgentRoles::class)->call('openStudio', 'writer', 'training', 'import');
        $c->assertSeeHtml('data-testid="train-template-banner"')->assertSee('Bảng tiêu chí (.csv)')->assertSee('Văn bản (.md)');
        $c->call('downloadTrainTemplate', 'csv')->assertFileDownloaded('mau-huan-luyen-writer.csv');
        Livewire::test(AgentRoles::class)->call('openStudio', 'writer', 'training', 'import')->call('downloadTrainTemplate', 'md')->assertFileDownloaded('mau-huan-luyen-writer.md');
    }
}
