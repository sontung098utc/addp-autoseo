<?php

namespace Tests\Feature\Brain;

use App\Filament\Pages\BrainLibraryPage;
use App\Filament\Pages\BrainPage;
use App\Models\Brain;
use App\Models\BrainProfile;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeSource;
use App\Models\User;
use App\Models\Website;
use App\Services\Brain\BrainService;
use App\Services\Brain\BrainTemplates;
use App\Services\Knowledge\KnowledgeError;
use Database\Seeders\HealthBrainsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** AG-B2 phần B: 14 mẫu sức khỏe (chỉ cấu trúc), trình cài đặt idempotent, lọc Thư viện, quyền. Không gọi AI thật. Mức: test giả lập trên SQLite. */
class BrainHealthTemplatesTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::create(['name' => $role, 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => $role, 'is_active' => true]);
    }

    /** @return array<string,array<string,mixed>> */
    private function health(): array
    {
        return array_intersect_key(BrainTemplates::all(), array_flip(BrainTemplates::HEALTH_KEYS));
    }

    public function test_co_dung_14_mau_suc_khoe_voi_ten_theo_yeu_cau(): void
    {
        $names = array_map(fn ($t) => $t['name'], $this->health());
        $this->assertCount(14, $names);
        $this->assertSame([
            'y_khoa_tong_quat' => 'Y khoa tổng quát và sức khỏe cộng đồng', 'dai_thao_duong' => 'Đái tháo đường (tiểu đường)', 'tim_mach_huyet_ap' => 'Tim mạch và tăng huyết áp',
            'dinh_duong' => 'Dinh dưỡng và chế độ ăn', 'duoc_thuoc' => 'Dược và sử dụng thuốc an toàn', 'thuc_pham_chuc_nang' => 'Thực phẩm bảo vệ sức khỏe',
            'suc_khoe_phu_nu' => 'Sức khỏe phụ nữ và thai sản', 'nhi_khoa' => 'Sức khỏe trẻ em', 'tam_than_giac_ngu' => 'Sức khỏe tinh thần và giấc ngủ',
            'y_hoc_co_truyen' => 'Y học cổ truyền và thảo dược', 'da_lieu' => 'Da liễu và chăm sóc da', 'xuong_khop' => 'Xương khớp và vận động',
            'tieu_hoa_gan_mat' => 'Tiêu hóa và gan mật', 'ho_hap_di_ung' => 'Hô hấp và dị ứng',
        ], $names);
        $this->assertArrayHasKey('y_khoa_duoc', BrainTemplates::all(), 'giữ mẫu cũ để tương thích');
    }

    public function test_moi_mau_suc_khoe_la_must_cite_va_rui_ro_cao_co_khung_day_du(): void
    {
        foreach ($this->health() as $key => $t) {
            $this->assertSame('must_cite', $t['ai_reliance'], $key);
            $this->assertSame('high', $t['risk_class'], $key);
            $this->assertNotSame('', trim((string) $t['reliance_reason']), "$key: phải ghi lý do mức dựa vào AI");
            $this->assertSame('suc_khoe', BrainTemplates::groupOf($key));
            $this->assertGreaterThanOrEqual(3, count($t['topics']), "$key: cây chủ đề");
            foreach ($t['topics'] as $children) {
                $this->assertNotEmpty($children, "$key: cây chủ đề 2 cấp");
            }
            $this->assertGreaterThanOrEqual(3, count($t['profile']['structure']['article_types']), "$key: loại bài");
            $this->assertGreaterThanOrEqual(8, count($t['profile']['terminology']['preferred']), "$key: tên thuật ngữ");
            $this->assertNotSame('', $t['profile']['rubric_notes'], "$key: rubric gợi ý");
            // Quy tắc an toàn chung có đủ và đứng trước quy tắc riêng.
            $rules = $t['profile']['reasoning']['rules'];
            foreach (BrainTemplates::HEALTH_RULES as $r) {
                $this->assertContains($r, $rules, "$key: thiếu quy tắc an toàn chung");
            }
            $this->assertGreaterThan(count(BrainTemplates::HEALTH_RULES), count($rules), "$key: thiếu quy tắc riêng của lĩnh vực");
            $this->assertStringContainsString('Đồng cảm', implode(' ', $t['profile']['voice']['do']));
        }
    }

    public function test_mau_khong_chua_su_kien_so_lieu_lieu_luong(): void
    {
        $forbiddenKeys = ['value', 'price', 'gia', 'dose', 'lieu', 'url', 'link'];
        $unit = '/(?<![\p{L}])(mg|mcg|µg|ml|mmol|mmhg|iu|kg|kcal)(?![\p{L}])|%|\d|https?:\/\//iu';
        $digitTermExceptions = ['HbA1c']; // ngoại lệ rõ ràng: TÊN thuật ngữ có chữ số, không phải số liệu
        foreach ($this->health() as $key => $t) {
            $walk = function ($node, string $path) use (&$walk, $forbiddenKeys, $unit, $digitTermExceptions, $key) {
                if (is_array($node)) {
                    foreach ($node as $k => $v) {
                        if (is_string($k)) {
                            $this->assertNotContains(strtolower($k), $forbiddenKeys, "$key: khóa cấm tại $path.$k");
                        }
                        $walk($v, $path.'.'.$k);
                    }
                } elseif (is_string($node) && ! (str_contains($path, 'terminology') && in_array($node, $digitTermExceptions, true))) {
                    $this->assertDoesNotMatchRegularExpression($unit, $node, "$key: số liệu/đơn vị/URL tại $path: $node");
                }
            };
            $walk($t, $key);
        }
    }

    public function test_thuat_ngu_khong_co_dinh_nghia_va_bai_mau_trong(): void
    {
        foreach ($this->health() as $key => $t) {
            foreach ($t['profile']['terminology']['preferred'] as $term) {
                $this->assertNotSame('', trim($term['term']), $key);
                $this->assertSame('', $term['definition'], "$key: '{$term['term']}' không được có định nghĩa (cần chuyên gia điền)");
                $this->assertSame([], $term['avoid']);
            }
            $this->assertSame([], $t['profile']['exemplars']);
            $this->assertSame('', $t['profile']['voice']['tone']);
        }
    }

    public function test_nguon_goi_y_chi_thuoc_danh_sach_cho_phep_va_deu_co_nhan_chua_xac_nhan(): void
    {
        $allowed = ['who.int', 'moh.gov.vn', 'dav.gov.vn', 'nin.gov.vn', 'pubmed.ncbi.nlm.nih.gov', 'cochranelibrary.com', 'nih.gov', 'niddk.nih.gov', 'cdc.gov', 'nhs.uk', 'diabetes.org', 'idf.org', 'heart.org', 'escardio.org'];
        $this->assertEqualsCanonicalizing($allowed, array_keys(BrainTemplates::ALLOWED_SOURCES));
        foreach ($this->health() as $key => $t) {
            $this->assertNotEmpty($t['suggested_sources'], $key);
            foreach ($t['suggested_sources'] as $s) {
                $this->assertContains($s['domain'], $allowed, "$key: tên miền ngoài danh sách đã rà");
                $this->assertStringContainsString('chưa xác nhận', $s['status'], $key);
                $this->assertStringContainsString('cần chuyên gia duyệt', $s['status'], $key);
                $this->assertNotSame('', $s['org']);
            }
            $domains = array_column($t['suggested_sources'], 'domain');
            $this->assertSame($domains, array_values(array_unique($domains)), "$key: trùng tên miền");
        }
        // Nguồn riêng của lĩnh vực không bị sao chép cho mọi mẫu.
        $this->assertContains('diabetes.org', array_column($this->health()['dai_thao_duong']['suggested_sources'], 'domain'));
        $this->assertNotContains('diabetes.org', array_column($this->health()['da_lieu']['suggested_sources'], 'domain'));
        $this->assertContains('dav.gov.vn', array_column($this->health()['duoc_thuoc']['suggested_sources'], 'domain'));
        $this->assertContains('heart.org', array_column($this->health()['tim_mach_huyet_ap']['suggested_sources'], 'domain'));
    }

    public function test_cac_mau_khac_nhau_khong_sao_chep_mot_khung(): void
    {
        $topicSets = [];
        $typeKeys = [];
        $typeNames = [];
        $outlines = [];
        $termSets = [];
        $ruleSets = [];
        foreach ($this->health() as $key => $t) {
            $topicSets[$key] = json_encode($t['topics']);
            $termSets[$key] = json_encode(array_column($t['profile']['terminology']['preferred'], 'term'));
            $own = array_slice($t['profile']['reasoning']['rules'], count(BrainTemplates::HEALTH_RULES));
            $ruleSets[$key] = json_encode($own);
            foreach ($t['profile']['structure']['article_types'] as $at) {
                $typeKeys[] = $at['key'];
                $typeNames[] = $at['name'];
                $outlines[] = json_encode($at['outline']);
            }
        }
        foreach (['topics' => $topicSets, 'terms' => $termSets, 'rules' => $ruleSets] as $what => $sets) {
            $this->assertCount(14, array_unique($sets), "$what trùng giữa các mẫu");
        }
        $this->assertSame($typeKeys, array_values(array_unique($typeKeys)), 'khóa loại bài trùng giữa các mẫu');
        $this->assertSame($typeNames, array_values(array_unique($typeNames)), 'tên loại bài trùng giữa các mẫu');
        $this->assertSame($outlines, array_values(array_unique($outlines)), 'dàn ý loại bài trùng giữa các mẫu');
        $parents = array_merge(...array_values(array_map(fn ($t) => array_keys($t['topics']), $this->health())));
        $this->assertGreaterThan(40, count(array_unique($parents)), 'cây chủ đề cấp 1 phải đa dạng');
    }

    public function test_quy_tac_rieng_cua_linh_vuc_nhay_cam(): void
    {
        $rules = fn (string $k) => mb_strtolower(implode(' ', $this->health()[$k]['profile']['reasoning']['rules']));
        $this->assertStringContainsString('không phải là thuốc', $rules('thuc_pham_chuc_nang'));
        $this->assertStringContainsString('không giới thiệu sản phẩm như thuốc', $rules('thuc_pham_chuc_nang'));
        $this->assertStringContainsString('không khẳng định chữa khỏi', $rules('y_hoc_co_truyen'));
        $this->assertStringContainsString('bác sĩ nhi', $rules('nhi_khoa'));
        $this->assertStringContainsString('bác sĩ sản khoa', $rules('suc_khoe_phu_nu'));
        $this->assertStringContainsString('không nêu liều', $rules('duoc_thuoc'));
        $this->assertStringContainsString('gọi cấp cứu', $rules('tim_mach_huyet_ap'));
        $this->assertStringContainsString('không gán nhãn bệnh', $rules('tam_than_giac_ngu'));
        $this->assertStringContainsString('không chẩn đoán', $rules('da_lieu'));
        foreach ($this->health() as $key => $t) { // an toàn chung: không chẩn đoán, không kê đơn/liều
            $this->assertStringContainsString('không chẩn đoán', $rules($key));
        }
    }

    public function test_install_idempotent_khong_ghi_de_ban_da_sua_va_khong_tu_gan_website(): void
    {
        $site = Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://x.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $svc = new BrainService;
        $r = $svc->installTemplates(null, null, $this->user('admin'));
        $this->assertCount(14, $r['created']);
        $this->assertSame([], $r['skipped']);
        $this->assertSame(14, Brain::count());
        $this->assertSame(0, \DB::table('brain_website')->count(), 'không tự gắn website');
        $this->assertSame(0, KnowledgeSource::count());
        $this->assertSame(0, KnowledgeItem::count(), 'sources/atoms = 0');
        foreach (Brain::all() as $b) {
            $this->assertSame('must_cite', $b->reliance());
            $this->assertSame('high', $b->riskClass());
            $this->assertSame('Mẫu: chưa có kiến thức nào được duyệt', $b->settings['sample_label']);
            $this->assertNotEmpty($b->settings['suggested_sources']);
            $this->assertSame($b->template_key, array_search($b->name, array_map(fn ($t) => $t['name'], BrainTemplates::all())));
        }

        // Người dùng sửa một Trí tuệ rồi cài lại: không tạo trùng, không ghi đè.
        $b = Brain::where('template_key', 'dai_thao_duong')->firstOrFail();
        $b->profile->update(['voice' => ['tone' => 'Ấm áp', 'formality' => '', 'pronouns' => '', 'do' => [], 'dont' => []]]);
        $b->update(['settings' => array_merge($b->settings, ['ai_reliance' => 'numbers'])]);
        $r2 = $svc->installTemplates(null, $site, $this->user('admin'));
        $this->assertSame([], $r2['created']);
        $this->assertCount(14, $r2['skipped']);
        $this->assertSame(14, Brain::count());
        $this->assertSame('Ấm áp', $b->fresh()->profile->voice['tone']);
        $this->assertSame('numbers', $b->fresh()->reliance());
        $this->assertSame(14, BrainProfile::count());

        // Chỉ chọn một vài mẫu; khóa lạ được báo; gắn website khi truyền $site.
        Brain::where('template_key', 'nhi_khoa')->delete();
        $r3 = $svc->installTemplates(['nhi_khoa', 'khong_co_mau'], $site, $this->user('admin'));
        $this->assertCount(1, $r3['created']);
        $this->assertSame(['khong_co_mau'], $r3['unknown']);
        $this->assertSame([$site->id], $r3['created'][0]->websites()->pluck('websites.id')->all());
    }

    public function test_install_chi_admin_con_lenh_va_seeder(): void
    {
        $svc = new BrainService;
        foreach (['approver', 'writer'] as $role) {
            try {
                $svc->installTemplates(null, null, $this->user($role));
                $this->fail("$role không được cài");
            } catch (KnowledgeError $e) {
                $this->assertStringContainsString('quản trị viên', $e->getMessage());
            }
        }
        $this->assertSame(0, Brain::count());

        $this->artisan('brains:install-health')->assertSuccessful();
        $this->assertSame(14, Brain::count());
        $this->artisan('brains:install-health')->assertSuccessful();
        $this->artisan('brains:install-health', ['--only' => ['khong_co']])->assertFailed();
        (new HealthBrainsSeeder)->run();
        $this->assertSame(14, Brain::count(), 'chạy lại không tạo trùng');
        $this->assertSame(0, \DB::table('brain_website')->count());
    }

    public function test_thu_vien_nut_cai_dat_chi_admin_va_tick_chon_mau(): void
    {
        $this->actingAs($this->user('approver'));
        Livewire::test(BrainLibraryPage::class)->assertOk()->assertDontSee('Cài các trí tuệ mẫu')->call('openInstall')->assertForbidden();
        Livewire::test(BrainLibraryPage::class)->call('installSelected')->assertForbidden();
        $this->assertSame(0, Brain::count());

        $this->actingAs($this->user('admin'));
        $t = Livewire::test(BrainLibraryPage::class)->assertSee('Cài các trí tuệ mẫu')->call('openInstall')->assertSet('showInstall', true)
            ->assertSee('Đái tháo đường (tiểu đường)')->assertSee('Hô hấp và dị ứng');
        $t->set('installKeys', ['dai_thao_duong', 'nhi_khoa', 'khong_co_mau'])->call('installSelected')->assertHasNoErrors()->assertSet('showInstall', false);
        $this->assertEqualsCanonicalizing(['dai_thao_duong', 'nhi_khoa'], Brain::pluck('template_key')->all());
        $t->call('openInstall')->set('installKeys', [])->call('installSelected');
        $this->assertSame(2, Brain::count());
    }

    public function test_thu_vien_loc_theo_nhom_va_tim_theo_ten(): void
    {
        $svc = new BrainService;
        $admin = $this->user('admin');
        $svc->installTemplates(['dai_thao_duong', 'nhi_khoa', 'da_lieu'], null, $admin);
        $svc->createFromTemplate('Thương hiệu A', 'thuong_hieu', [], $admin);
        $svc->createFromTemplate('Sản phẩm B', 'san_pham', [], $admin);
        $svc->createFromTemplate('Tiếng Anh', 'ngoai_ngu', [], $admin);
        $svc->createFromTemplate('Tự tạo', null, [], $admin);
        $this->actingAs($admin);

        $names = fn ($t) => $t->viewData('cards')->map(fn ($c) => $c['brain']->name)->sort()->values()->all();
        $t = Livewire::test(BrainLibraryPage::class);
        $this->assertCount(7, $names($t));
        $this->assertSame(['Da liễu và chăm sóc da', 'Sức khỏe trẻ em', 'Đái tháo đường (tiểu đường)'], $names($t->set('group', 'suc_khoe')));
        $this->assertSame(['Sản phẩm B', 'Thương hiệu A'], $names($t->set('group', 'thuong_hieu')));
        $this->assertSame(['Tiếng Anh', 'Tự tạo'], $names($t->set('group', 'khac')));
        $this->assertSame(['Tự tạo'], $names($t->set('search', 'tạo')));
        $this->assertSame([], $names($t->set('search', 'không có tên này')));
        $this->assertSame(['Sức khỏe trẻ em'], $names($t->set('group', 'suc_khoe')->set('search', 'trẻ')));
        $this->assertSame(['Da liễu và chăm sóc da', 'Sức khỏe trẻ em', 'Đái tháo đường (tiểu đường)'], $names($t->set('search', '')->set('group', 'suc_khoe')));
        $t->set('group', '')->set('search', '%'); // ký tự đặc biệt của LIKE được escape, không khớp tất cả
        $this->assertSame([], $names($t));
    }

    public function test_trang_tri_tue_hien_nguon_goi_y_chi_doc_kem_canh_bao_va_nhan_mau(): void
    {
        (new BrainService)->installTemplates(['duoc_thuoc'], null, $this->user('admin'));
        $b = Brain::firstOrFail();
        $this->actingAs($this->user('approver'));
        $t = Livewire::test(BrainPage::class, ['brain' => $b->id])->assertOk()
            ->assertSee('Căn cứ chuẩn mực')->assertSee('dav.gov.vn')
            ->assertSee('Chưa xác nhận, chưa được tính')->assertSee('Mẫu: chưa có kiến thức nào được duyệt'); // P2: gợi ý nằm trong Căn cứ chuẩn mực, chưa có bậc nên chưa được tính
        $t->assertDontSee('brandData');
        $lib = Livewire::test(BrainLibraryPage::class)->assertSee('Mẫu: chưa có kiến thức nào được duyệt');
        $this->assertTrue($lib->viewData('cards')->first()['sample']);
    }
}
