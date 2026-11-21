<?php

namespace Tests\Feature\Planning;

use App\Filament\Clusters\AgentTeam\Pages\AgentRoles;
use App\Filament\Pages\ContentPlanPage;
use App\Models\User;
use App\Models\Website;
use App\Services\Agent\Roles\RoleTrainingImporter;
use App\Services\Agent\Roles\RoleTrainingTemplate;
use App\Services\Planning\Import\PlanBrief;
use App\Services\Planning\Import\PlanFileHeuristics;
use App\Services\Planning\Import\PlanFileImporter;
use App\Services\Planning\Import\PlanFileTemplate;
use App\Services\Planning\Import\SheetReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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

    /** @return array<string,string> tiêu đề cột mẫu => trường mong đợi */
    private function expectedMap(): array
    {
        return [
            'Từ khóa chính' => 'primary_keyword', 'Từ khóa phụ' => 'secondary_keywords', 'Ý định tìm kiếm' => 'search_intent', 'Cụm chủ đề' => 'topic_cluster', 'Loại bài' => 'post_type',
            'Tiêu đề gợi ý' => 'title', 'Đối tượng đọc' => 'audience', 'Góc tiếp cận / điều cần nhấn' => 'angle', 'Giọng văn' => 'tone', 'Độ dài (số từ)' => 'length_words', 'Số bảng' => 'table_count',
            'Số ảnh tối đa' => 'image_count', 'Số mục H2 tối thiểu' => 'h2_count', 'Có mục hỏi đáp (FAQ)' => 'faq', 'Câu hỏi bài cần trả lời' => 'questions', 'Nguồn nên dùng' => 'must_sources',
            'Liên kết nội bộ nên có' => 'internal_links', 'Điều cần tránh' => 'avoid', 'Đối thủ / bài tham khảo' => 'competitor_refs', 'CTA / sản phẩm liên quan' => 'cta', 'Cần chuyên gia duyệt' => 'expert_review', 'Chuyên mục (CMS)' => 'cms_category', 'Slug mong muốn' => 'cms_slug', 'Tác giả hiển thị' => 'cms_author', 'Người duyệt' => 'reviewer',
            'Lượng tìm kiếm/tháng' => 'search_volume', 'Độ khó từ khóa' => 'difficulty', 'CPC (VNĐ)' => 'cpc', 'Mức cạnh tranh' => 'competition', 'Ngày dự kiến đăng' => 'planned_date', 'Mức ưu tiên' => 'priority', 'Tên chiến dịch' => 'campaign', 'Ghi chú khác' => 'guidance',
        ];
    }

    public function test_mau_ke_hoach_nhieu_sheet_moi_cot_anh_xa_dung_truong_va_co_du_sheet(): void
    {
        $this->assertSame(array_keys($this->expectedMap()), PlanFileTemplate::headers(), 'mẫu đủ và đúng thứ tự các cột');
        $path = $this->tmp(PlanFileTemplate::xlsx(), 'xlsx');
        $sheets = (new SheetReader)->read($path, 'xlsx');
        $this->assertSame(['Bài viết', 'Chiến dịch', 'Hướng dẫn', 'Ví dụ đã điền'], array_column($sheets, 'name'));
        $main = $sheets[0];
        $this->assertCount(1, $main['rows'], 'sheet Bài viết chỉ có dòng tiêu đề: không phải xóa dòng ví dụ');
        $map = PlanFileHeuristics::mapByHeader($main, 1);
        $this->assertSame(array_values($this->expectedMap()), array_values($map), 'mọi cột của mẫu được nhận đúng trường');
        $this->assertCount(4, $sheets[3]['rows'], 'ví dụ: tiêu đề + 3 bài mẫu');
        $this->assertGreaterThanOrEqual(count($this->expectedMap()) + 1, count($sheets[2]['rows']), 'hướng dẫn nêu từng cột');
        $this->assertSame(['Tên chiến dịch', 'Mô tả chiến dịch', 'Chiến dịch bắt đầu', 'Chiến dịch kết thúc'], $sheets[1]['rows'][0]);
        @unlink($path);

        $csv = (new SheetReader)->read($this->tmp(PlanFileTemplate::csv(), 'csv'), 'csv')[0];
        $this->assertSame(array_values($this->expectedMap()), array_values(PlanFileHeuristics::mapByHeader($csv, 1)));
    }

    public function test_nhan_ra_file_mau_de_doc_truc_tiep_khong_can_ai_va_noi_sheet_chien_dich(): void
    {
        $sheets = (new SheetReader)->read($this->tmp(PlanFileTemplate::xlsx(), 'xlsx'), 'xlsx');
        $rec = PlanFileTemplate::recognize($sheets);
        $this->assertSame('Bài viết', $rec['sheet']);
        $this->assertSame(1, $rec['header_row']);
        $this->assertSame('Chiến dịch', $rec['links'][0]['sheet']);
        $this->assertSame(\App\Services\Planning\Import\PlanFileFields::colLetter(array_search('Tên chiến dịch', PlanFileTemplate::headers(), true)), $rec['links'][0]['from_col'], 'cột Tên chiến dịch của sheet Bài viết');
        $this->assertNull(PlanFileTemplate::recognize([['name' => 'Sheet 1', 'rows' => [['Từ khóa', 'Ngày'], ['a', '1/1/2026']], 'total' => 2, 'truncated' => false]]), 'file khác không bị nhận nhầm là mẫu');
    }

    public function test_brief_ghep_thanh_dong_co_nhan_dung_cau_bo_do_hieu_va_do_duoc(): void
    {
        $lines = PlanBrief::lines(['length_words' => '1.200-1.500', 'table_count' => '1', 'image_count' => '3 ảnh', 'h2_count' => '6', 'faq' => 'Có', 'audience' => 'Người trên 50 tuổi', 'avoid' => 'Không nêu liều thuốc', 'expert_review' => 'Có']);
        $text = implode("\n", $lines);
        $this->assertStringContainsString('Độ dài: ít nhất 1200 từ, tối đa 1500 từ', $text);
        $this->assertStringContainsString('Cấu trúc: đúng 1 bảng; tối đa 3 ảnh; ít nhất 6 mục H2', $text);
        $this->assertStringContainsString('Có mục câu hỏi thường gặp (FAQ).', $text);
        $this->assertStringContainsString('Đối tượng đọc: Người trên 50 tuổi', $text);
        $this->assertStringContainsString('Cần người có chuyên môn duyệt kỹ', $text);
        $labels = PlanBrief::measurable($text);
        foreach (['ít nhất 1200 từ', 'tối đa 1500 từ', 'đúng 1 bảng', 'tối đa 3 ảnh', 'ít nhất 6 mục H2'] as $want) {
            $this->assertContains($want, $labels, "bộ chấm phải đo được: {$want}");
        }
        $this->assertSame([null, 800, false], PlanBrief::length('tối đa 800 từ'));
        $this->assertSame([1500, 1500, true], PlanBrief::length('khoảng 1500'));
        $this->assertNull(PlanBrief::length('10'), 'dưới 50 từ không hợp lý');
        $this->assertTrue(PlanBrief::yesNo('x'));
        $this->assertFalse(PlanBrief::yesNo('Không'));
        $this->assertNull(PlanBrief::yesNo('có thể'), 'không rõ thì để trống');
    }

    public function test_nhap_tu_sheet_vi_du_ghep_brief_vao_chi_dan_va_dem_do_bao_phu(): void
    {
        $site = Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://s.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $ex = (new SheetReader)->read($this->tmp(PlanFileTemplate::xlsx(), 'xlsx'), 'xlsx')[3];
        $map = PlanFileHeuristics::mapByHeader($ex, 1);
        $r = (new PlanFileImporter)->build($site, $ex['rows'], $map, 1);
        $this->assertCount(3, $r['items']);
        $it = $r['items'][0];
        $this->assertStringContainsString('Độ dài: khoảng 1500 từ', $it['guidance']);
        $this->assertStringContainsString('Đối tượng đọc: Người trên 50 tuổi', $it['guidance']);
        $this->assertStringContainsString('Câu hỏi bài cần trả lời: Người tiểu đường ăn cơm trắng được không', $it['guidance']);
        $this->assertSame(28, $it['difficulty']);
        $this->assertContains('length_words', $it['brief']);
        $this->assertContains('khoảng 1500 từ', $it['measurable']);
        $this->assertSame(['chế độ ăn tiểu đường', 'thực phẩm hạ đường huyết'], $it['secondary']);
        $this->assertSame('Dinh dưỡng mùa đông', $it['campaign']);
        // thứ tự: điều đo được đứng trước chỉ dẫn mềm
        $this->assertLessThan(strpos($it['guidance'], 'Đối tượng đọc'), strpos($it['guidance'], 'Độ dài'));
    }

    public function test_chi_dan_qua_dai_bi_cat_nhung_dieu_do_duoc_duoc_giu_va_co_canh_bao(): void
    {
        $site = Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://s.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $long = fn ($w) => trim(str_repeat($w.' ', 90));
        $rows = [['Từ khóa chính', 'Độ dài (số từ)', 'Góc tiếp cận / điều cần nhấn', 'Điều cần tránh', 'Đối tượng đọc', 'Giọng văn', 'Câu hỏi bài cần trả lời'], ['bài dài', '1200', $long('ý'), $long('tránh'), $long('người'), $long('giọng'), $long('hỏi')]];
        $r = (new PlanFileImporter)->build($site, $rows, PlanFileHeuristics::mapByHeader(['rows' => $rows], 1), 1);
        $g = $r['items'][0]['guidance'];
        $this->assertLessThanOrEqual(PlanBrief::GUIDANCE_MAX, mb_strlen($g));
        $this->assertStringStartsWith('Độ dài: khoảng 1200 từ', $g, 'điều đo được luôn đứng đầu');
        $this->assertNotEmpty($r['warnings']);
        $this->assertStringContainsString('bị cắt', $r['warnings'][0]);
    }

    public function test_file_khong_theo_mau_nhung_co_cot_tuong_tu_van_duoc_nhan_dung_truong(): void
    {
        foreach ([
            'Đối tượng' => 'audience', 'Giọng điệu' => 'tone', 'Số từ' => 'length_words', 'Số bảng' => 'table_count', 'Link nội bộ' => 'internal_links', 'Cần tránh' => 'avoid',
            'Chuyên gia duyệt' => 'expert_review', 'KD' => 'difficulty', 'Hỏi đáp' => 'faq', 'Nguồn tham khảo' => 'must_sources', 'Đối thủ' => 'competitor_refs', 'Góc viết' => 'angle',
            'Loại bài' => 'post_type', 'Câu hỏi cần trả lời' => 'questions', 'CTA' => 'cta',
        ] as $header => $field) {
            $this->assertSame($field, PlanFileHeuristics::fieldForHeader($header), "cột \"{$header}\" phải nhận là {$field}");
        }
        $this->assertSame('cpc', PlanFileHeuristics::fieldForHeader('CPC'));
        $this->assertSame('competition', PlanFileHeuristics::fieldForHeader('Mức cạnh tranh'));
        $this->assertSame('ignore', PlanFileHeuristics::fieldForHeader('Người phụ trách'));
        $this->assertSame('priority', PlanFileHeuristics::fieldForHeader('Mức ưu tiên'), 'không bị luật mới nuốt');
    }

    public function test_tai_mau_ke_hoach_tren_trang_ke_hoach(): void
    {
        $this->actingAs($this->user('approver'));
        Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://s.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        Livewire::test(ContentPlanPage::class)->assertSeeHtml('data-testid="plan-template-banner"')->assertSee('Chưa có file? Tải mẫu:'); // đã thấy ngay ở khung giới thiệu, trước khi chọn file
        $c = Livewire::test(ContentPlanPage::class)->call('openImport');
        $c->assertSee('Chưa có file? Tải mẫu:')->assertSeeHtml('data-testid="plan-template-xlsx"');
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

    public function test_giao_dien_nhan_file_mau_doc_truc_tiep_khong_goi_ai_va_hien_do_bao_phu_brief(): void
    {
        $site = Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://s.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        // AI bị cấm gọi: nếu hệ thống gọi AI cho file mẫu thì test lỗi.
        $this->app->bind(\App\Services\Planning\Import\PlanFileMapper::class, fn () => new class extends \App\Services\Planning\Import\PlanFileMapper
        {
            public function map(array $sheets, ?string $forceSheet = null): array
            {
                throw new \RuntimeException('AI không được gọi với file mẫu');
            }
        });
        $h = PlanFileTemplate::headers();
        $mk = function (array $vals) use ($h) {
            $row = array_fill(0, count($h), '');
            foreach ($vals as $k => $v) {
                $row[array_search($k, $h, true)] = $v;
            }

            return $row;
        };
        $fh = fopen('php://temp', 'r+');
        fputcsv($fh, $h);
        fputcsv($fh, $mk(['Từ khóa chính' => 'chế độ ăn cho người gút', 'Độ dài (số từ)' => '1500', 'Số bảng' => '1', 'Đối tượng đọc' => 'Người trên 40 tuổi', 'Cần chuyên gia duyệt' => 'Có', 'Tên chiến dịch' => 'Mùa đông']));
        fputcsv($fh, $mk(['Từ khóa chính' => 'thực đơn giảm mỡ máu', 'Độ dài (số từ)' => '1200-1500', 'Tên chiến dịch' => 'Mùa đông']));
        rewind($fh);
        $file = UploadedFile::fake()->createWithContent('kh.csv', "\xEF\xBB\xBF".stream_get_contents($fh));

        $page = Livewire::actingAs($this->user('approver'))->test(ContentPlanPage::class)->call('openImport')->set('planFile', $file);
        $page->assertSee('Nhận ra file mẫu của hệ thống')->assertSeeHtml('data-testid="plan-brief-cov"')->assertSee('Thông tin viết bài tìm thấy trong file')
            ->assertSee('Nhận ra file mẫu')->assertSeeHtml('data-testid="plan-guidance-preview"')->assertSee('Độ dài (số từ) · 2/2')->assertSee('Đối tượng đọc · 1/2')->assertSee('Bộ chấm sẽ ĐO 2 bài')->assertSee('Nên bổ sung');
        $pv = $page->get('importPreview');
        $this->assertSame(2, $pv['count']);
        $this->assertSame(2, $pv['measurable']);
    }

    public function test_cpc_va_canh_tranh_duoc_chuan_hoa(): void
    {
        $this->assertSame(4500, \App\Services\Planning\Import\PlanFileFields::cpc('4.500'));
        $this->assertSame(0.35, \App\Services\Planning\Import\PlanFileFields::competition('0,35'));
        $this->assertSame(0.35, \App\Services\Planning\Import\PlanFileFields::competition('35'), 'thang 0-100 quy về 0-1');
        $this->assertSame(0.2, \App\Services\Planning\Import\PlanFileFields::competition('Thấp'));
        $this->assertNull(\App\Services\Planning\Import\PlanFileFields::competition('150'));
        $this->assertNull(\App\Services\Planning\Import\PlanFileFields::cpc('abc'));
    }

    public function test_url_noi_bo_bi_loai_khoi_cot_nguon_va_doi_thu(): void
    {
        foreach (['http://localhost/admin', 'http://127.0.0.1:8000', 'https://192.168.1.5/x', 'http://169.254.169.254/latest', 'https://may.local/a', 'ftp://x.vn/a', 'javascript:alert(1)'] as $bad) {
            $this->assertFalse(PlanBrief::safeUrl($bad), $bad);
        }
        $this->assertTrue(PlanBrief::safeUrl('https://www.who.int/health-topics'));
        $r = PlanBrief::splitRefs('Bộ Y tế; https://www.who.int/x; http://localhost:8080/secret; http://10.0.0.5/a');
        $this->assertSame(['Bộ Y tế', 'https://www.who.int/x'], $r['kept']);
        $this->assertCount(2, $r['dropped']);
    }

    public function test_lien_ket_noi_bo_khop_bai_co_san_hoac_trong_file_va_url_noi_bo_bi_bo_kem_canh_bao(): void
    {
        $site = Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://s.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        \App\Models\Article::create(['website_id' => $site->id, 'primary_keyword' => 'chỉ số đường huyết', 'workflow_status' => 'published']);
        $rows = [['Từ khóa chính', 'Liên kết nội bộ nên có', 'Đối thủ / bài tham khảo'],
            ['thực đơn tiểu đường', 'chỉ số đường huyết; ăn giảm muối; https://s.vn/bai-a', 'http://localhost/x; https://example.com/a'],
            ['ăn giảm muối', '', '']];
        $r = (new PlanFileImporter)->build($site, $rows, PlanFileHeuristics::mapByHeader(['rows' => $rows], 1), 1);
        $lk = $r['items'][0]['link_report'];
        $this->assertSame(2, $lk['match'], 'một bài đã có trên website, một bài khác trong file');
        $this->assertSame(1, $lk['url']);
        $this->assertSame([], $lk['missing']);
        $g = $r['items'][0]['guidance'];
        $this->assertStringContainsString('example.com', $g);
        $this->assertStringNotContainsString('localhost', $g, 'địa chỉ nội bộ không được lưu');
        $this->assertStringContainsString('địa chỉ nội bộ', implode(' ', $r['warnings']));
    }

    public function test_nhap_luu_do_kho_cpc_canh_tranh_vao_kho_tu_khoa_chi_cho_con_trong(): void
    {
        $site = Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://s.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        \App\Models\Keyword::create(['website_id' => $site->id, 'keyword' => 'bài có sẵn số liệu', 'keyword_key' => \App\Models\Keyword::keyFor('bài có sẵn số liệu'), 'volume' => 999, 'difficulty' => 70, 'source' => 'csv', 'status' => 'new']);
        $rows = [['Từ khóa chính', 'Lượng tìm kiếm/tháng', 'Độ khó từ khóa', 'CPC (VNĐ)', 'Mức cạnh tranh'],
            ['bài mới có đủ số liệu', '1200', '33', '4.500', '0,4'],
            ['bài có sẵn số liệu', '5000', '10', '3000', 'cao']];
        $map = PlanFileHeuristics::mapByHeader(['rows' => $rows], 1);
        $imp = new PlanFileImporter;
        $b = $imp->build($site, $rows, $map, 1);
        $res = $imp->apply($this->user('approver'), $site, $b['items'], PlanFileImporter::TARGET_SOLO);
        $new = \App\Models\Keyword::where('keyword_key', \App\Models\Keyword::keyFor('bài mới có đủ số liệu'))->first();
        $this->assertSame([1200, 33, 4500, 0.4], [$new->volume, $new->difficulty, $new->cpc_vnd, (float) $new->competition]);
        $old = \App\Models\Keyword::where('keyword_key', \App\Models\Keyword::keyFor('bài có sẵn số liệu'))->first();
        $this->assertSame([999, 70], [$old->volume, $old->difficulty], 'không ghi đè số liệu đã có');
        $this->assertSame(3000, $old->cpc_vnd, 'chỗ còn trống thì được điền');
        $this->assertStringContainsString('Độ khó, CPC, mức cạnh tranh', implode(' ', $res['notes']));
    }

    public function test_lien_ket_noi_bo_khop_bai_da_dang_thi_ghi_kem_url_that(): void
    {
        $site = Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://s.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $a = \App\Models\Article::create(['website_id' => $site->id, 'primary_keyword' => 'chỉ số đường huyết', 'workflow_status' => 'published']);
        $v = \App\Models\ArticleVersion::create(['article_id' => $a->id, 'version' => 1, 'title' => 't', 'slug' => 'csdh', 'html_content' => '<p>x</p>', 'review_status' => 'approved']);
        \App\Models\Publication::create(['website_id' => $site->id, 'article_version_id' => $v->id, 'status' => 'published', 'published_url' => 'https://s.vn/chi-so-duong-huyet', 'publication_key' => 'k1', 'scheduled_at_utc' => now(), 'timezone' => 'Asia/Ho_Chi_Minh']);
        $rows = [['Từ khóa chính', 'Liên kết nội bộ nên có'], ['thực đơn tiểu đường', 'chỉ số đường huyết']];
        $r = (new PlanFileImporter)->build($site, $rows, PlanFileHeuristics::mapByHeader(['rows' => $rows], 1), 1);
        $this->assertSame(1, $r['items'][0]['link_report']['resolved']);
        $this->assertStringContainsString('chỉ số đường huyết (https://s.vn/chi-so-duong-huyet)', $r['items'][0]['guidance']);
    }

    public function test_brief_json_luu_cung_bai_gom_thong_tin_xuat_ban_va_nguoi_duyet(): void
    {
        $site = Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://s.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $u = $this->user('approver');
        $rows = [['Từ khóa chính', 'Đối tượng đọc', 'Chuyên mục (CMS)', 'Slug mong muốn', 'Tác giả hiển thị', 'Người duyệt'],
            ['bài brief một', 'Người cao tuổi', 'Dinh dưỡng', 'Thực Đơn Tiểu Đường!', 'Ban biên tập', $u->email],
            ['bài brief hai', '', '', '', '', 'Không có người này']];
        $map = PlanFileHeuristics::mapByHeader(['rows' => $rows], 1);
        $this->assertSame(['primary_keyword', 'audience', 'cms_category', 'cms_slug', 'cms_author', 'reviewer'], array_values($map));
        $imp = new PlanFileImporter;
        $b = $imp->build($site, $rows, $map, 1);
        $this->assertStringContainsString('không khớp tài khoản', implode(' ', $b['warnings']));
        $imp->apply($u, $site, $b['items'], PlanFileImporter::TARGET_SOLO);
        $a = \App\Models\Article::where('primary_keyword', 'bài brief một')->first();
        $this->assertSame('Người cao tuổi', $a->brief['audience']);
        $this->assertSame('thuc-don-tieu-duong', $a->brief['cms_slug']);
        $this->assertSame('Dinh dưỡng', $a->brief['cms_category']);
        $this->assertSame($u->id, $a->brief['reviewer_user_id']);
        $this->assertNull(\App\Models\Article::where('primary_keyword', 'bài brief hai')->first()->brief['reviewer_user_id'] ?? null);
    }

    public function test_slug_tach_tu_o_dau_gach_cheo_va_dau_cau_khong_dinh_chu(): void
    {
        $site = Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://s.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $u = $this->user('approver');
        $rows = [['Từ khóa chính', 'Slug mong muốn'], ['bài slug một', '  Ăn Gì/Khi Mỡ Máu_cao.2026  ']];
        $map = PlanFileHeuristics::mapByHeader(['rows' => $rows], 1);
        $imp = new PlanFileImporter;
        $b = $imp->build($site, $rows, $map, 1);
        $imp->apply($u, $site, $b['items'], PlanFileImporter::TARGET_SOLO);
        $this->assertSame('an-gi-khi-mo-mau-cao-2026', \App\Models\Article::where('primary_keyword', 'bài slug một')->first()->brief['cms_slug']);
    }
}
