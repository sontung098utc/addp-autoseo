<?php

namespace Tests\Feature\Brain;

use App\Models\Brain;
use App\Models\BrainProfile;
use App\Models\KnowledgeGap;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeSource;
use App\Models\KnowledgeTopic;
use App\Models\User;
use App\Models\Website;
use App\Services\Brain\BrainService;
use App\Services\Brain\BrainTemplates;
use App\Services\Knowledge\KnowledgeDedupe;
use App\Services\Knowledge\KnowledgeError;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** AG-B1: migration dữ liệu (idempotent, gán brain_id), mẫu trí tuệ không chứa sự kiện, createFromTemplate, hồ sơ lưu/validate. Mức: test giả lập trên SQLite. */
class BrainFoundationTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::create(['name' => $role, 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => $role, 'is_active' => true]);
    }

    private function site(string $name = 'S'): Website
    {
        return Website::create(['name' => $name, 'cms_type' => 'wordpress', 'base_url' => 'https://'.uniqid().'.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
    }

    private function item(array $a = []): KnowledgeItem
    {
        $a += ['type' => 'fact', 'title' => 'Mục '.uniqid(), 'body' => 'Nội dung mục kiến thức đủ dài.', 'status' => 'approved', 'version' => 1, 'created_by' => 'rule'];
        $a['content_hash'] = KnowledgeDedupe::hash($a['title'], $a['body']);

        return KnowledgeItem::create($a);
    }

    private function runDataMigration(): void
    {
        $m = require database_path('migrations/2026_11_18_000001_create_brains_tables.php');
        $m->up();
    }

    public function test_migration_du_lieu_gan_brain_id_va_chay_lai_khong_nhan_doi(): void
    {
        $a = $this->site('Alpha');
        $b = $this->site('Beta');
        $ia = $this->item(['website_id' => $a->id]);
        $ib = $this->item(['website_id' => $b->id]);
        $ig = $this->item(['website_id' => null]);
        $sa = KnowledgeSource::create(['website_id' => $a->id, 'title' => 'N', 'raw_text' => 'x']);
        $ta = KnowledgeTopic::create(['website_id' => $a->id, 'name' => 'T']);
        $ga = KnowledgeGap::create(['website_id' => $a->id, 'query' => 'q']);
        $sg = KnowledgeSource::create(['website_id' => null, 'title' => 'Chung', 'raw_text' => 'x']);

        $this->runDataMigration();
        $snapshot = [Brain::count(), \DB::table('brain_website')->count(), \DB::table('brain_profiles')->count()];
        $this->runDataMigration(); // lần 2: không tạo thêm
        $this->assertSame($snapshot, [Brain::count(), \DB::table('brain_website')->count(), \DB::table('brain_profiles')->count()]);

        $brainA = Brain::where('slug', 'tri-tue-website-'.$a->id)->firstOrFail();
        $brainB = Brain::where('slug', 'tri-tue-website-'.$b->id)->firstOrFail();
        $common = Brain::where('slug', 'chung')->firstOrFail();
        $this->assertSame('Trí tuệ của Alpha', $brainA->name);
        $this->assertSame('thuong_hieu', $brainA->template_key);
        $this->assertSame('numbers', $brainA->reliance());
        $this->assertSame($brainA->id, $ia->fresh()->brain_id);
        $this->assertSame($brainB->id, $ib->fresh()->brain_id);
        $this->assertSame($common->id, $ig->fresh()->brain_id);
        $this->assertSame($brainA->id, $sa->fresh()->brain_id);
        $this->assertSame($brainA->id, $ta->fresh()->brain_id);
        $this->assertSame($brainA->id, $ga->fresh()->brain_id);
        $this->assertSame($common->id, $sg->fresh()->brain_id);
        $this->assertSame($a->id, $ia->fresh()->website_id, 'không đổi website_id');
        $this->assertEqualsCanonicalizing([$brainA->id, $common->id], $a->brains()->pluck('brains.id')->all());
        $this->assertEqualsCanonicalizing([$brainB->id, $common->id], $b->brains()->pluck('brains.id')->all());
        // Trí tuệ riêng của website được ưu tiên trước "Chung".
        $this->assertSame($brainA->id, $a->brains()->first()->id);

        // Bản ghi tạo sau đó (brain_id NULL) không bị gán nhầm khi chạy lại cho đến khi chính nó được xử lý.
        $late = $this->item(['website_id' => $a->id]);
        $this->runDataMigration();
        $this->assertSame($brainA->id, $late->fresh()->brain_id);
    }

    public function test_khong_co_muc_chung_thi_khong_tao_tri_tue_chung(): void
    {
        $this->site();
        $this->runDataMigration();
        $this->assertSame(0, Brain::where('slug', 'chung')->count());
        $this->assertSame(1, Brain::count());
    }

    public function test_mau_tri_tue_chi_co_cau_truc_khong_co_su_kien(): void
    {
        $all = BrainTemplates::all();
        $this->assertEqualsCanonicalizing(array_merge(['y_khoa_duoc', 'giao_duc', 'ngoai_ngu', 'khoa_hoc', 'thuong_hieu', 'san_pham', 'trong'], BrainTemplates::HEALTH_KEYS), array_keys($all));
        $forbiddenKeys = ['value', 'price', 'gia', 'url', 'link', 'brand', 'product'];
        foreach ($all as $key => $t) {
            $this->assertNotEmpty($t['name']);
            $this->assertArrayHasKey($t['ai_reliance'], Brain::RELIANCE, $key);
            $this->assertArrayHasKey($t['risk_class'], Brain::RISK, $key);
            foreach ($t['profile']['terminology']['preferred'] as $term) { // AG-B2: mẫu sức khỏe chỉ có TÊN thuật ngữ, định nghĩa luôn trống
                $this->assertSame('', $term['definition'], "$key: thuật ngữ không được có định nghĩa");
            }
            $this->assertTrue($t['profile']['terminology']['preferred'] === [] || in_array($key, array_merge(['y_khoa_duoc'], BrainTemplates::HEALTH_KEYS), true), "$key: chỉ mẫu sức khỏe được có tên thuật ngữ");
            $this->assertSame([], $t['profile']['terminology']['abbreviations'], $key);
            $this->assertSame([], $t['profile']['exemplars'], "$key: bài mẫu để trống");
            $this->assertSame('', $t['profile']['voice']['tone']);
            foreach ($t['topics'] as $parent => $children) {
                $this->assertIsString($parent);
                $this->assertIsArray($children);
                foreach ($children as $c) {
                    $this->assertIsString($c);
                }
            }
            $walk = function ($node, $path) use (&$walk, $forbiddenKeys, $key) {
                if (is_array($node)) {
                    foreach ($node as $k => $v) {
                        if (is_string($k)) {
                            $this->assertNotContains(strtolower($k), $forbiddenKeys, "$key: khóa cấm tại $path.$k");
                        }
                        $walk($v, $path.'.'.$k);
                    }
                } elseif (is_string($node)) {
                    if (str_contains($path, 'terminology') && in_array($node, ['HbA1c'], true)) { // ngoại lệ rõ ràng: TÊN thuật ngữ có chữ số
                        return;
                    }
                    $this->assertDoesNotMatchRegularExpression('/\d/', $node, "$key: chuỗi chứa chữ số (số liệu) tại $path: $node");
                    $this->assertDoesNotMatchRegularExpression('#https?://#i', $node, "$key: URL tại $path");
                }
            };
            $walk($t, $key);
        }
        $this->assertSame('must_cite', $all['y_khoa_duoc']['ai_reliance']);
        $this->assertSame('high', $all['y_khoa_duoc']['risk_class']);
        $rules = implode(' ', $all['y_khoa_duoc']['profile']['reasoning']['rules']);
        $this->assertStringContainsString('bác sĩ', $rules);
        $this->assertStringContainsString('miễn trừ', mb_strtolower($rules));
    }

    public function test_tao_tu_mau_tao_brain_ho_so_khung_chu_de_va_gan_website(): void
    {
        $w1 = $this->site('A');
        $w2 = $this->site('B');
        $admin = $this->user('admin');
        $brain = (new BrainService())->createFromTemplate('Y khoa – sức khỏe', 'y_khoa_duoc', [$w1->id, $w2], $admin);

        $this->assertSame('y-khoa-suc-khoe', $brain->slug);
        $this->assertSame('y_khoa_duoc', $brain->template_key);
        $this->assertSame('must_cite', $brain->reliance());
        $this->assertSame('high', $brain->riskClass());
        $this->assertSame($admin->id, $brain->created_by);
        $this->assertNotEmpty($brain->profile->structure['article_types'][0]['outline']);
        $this->assertSame([], $brain->profile->terminology['preferred']);
        $this->assertSame([$w1->id, $w2->id], $brain->websites()->pluck('websites.id')->all());
        $this->assertSame(4, KnowledgeTopic::where('brain_id', $brain->id)->whereNull('parent_id')->count());
        $this->assertGreaterThan(8, KnowledgeTopic::where('brain_id', $brain->id)->whereNotNull('parent_id')->count());
        $this->assertSame(0, KnowledgeItem::where('brain_id', $brain->id)->count(), 'mẫu không sinh mục kiến thức');
        // Trùng tên: slug khác.
        $again = (new BrainService())->createFromTemplate('Y khoa – sức khỏe', null, [], $admin);
        $this->assertSame('y-khoa-suc-khoe-2', $again->slug);
        $this->assertSame('trong', $again->template_key);
        $this->expectException(\InvalidArgumentException::class);
        (new BrainService())->createFromTemplate('X', 'khong_co_mau', [], $admin);
    }

    public function test_bien_tap_vien_khong_tao_duoc(): void
    {
        $this->expectException(KnowledgeError::class);
        (new BrainService())->createFromTemplate('X', 'trong', [], $this->user('writer'));
    }

    public function test_luu_ho_so_lam_sach_html_toi_da_5_bai_mau_va_do_day_du(): void
    {
        $admin = $this->user('approver');
        $brain = (new BrainService())->createFromTemplate('Thử', 'trong', [], $admin);
        $this->assertSame(0, $brain->profile->completeness()[0]);

        $svc = new BrainService();
        $p = $svc->saveProfile($brain, [
            'voice' => ['tone' => '  Ấm <b>áp</b> ', 'formality' => 'vừa', 'pronouns' => 'bạn', 'do' => ['Rõ ràng', 'rõ ràng', ' '], 'dont' => ['<script>x</script>Hứa chắc']],
            'structure' => ['article_types' => [['name' => 'Giải thích', 'outline' => ['Là gì', 'Vì sao']], ['name' => '  ', 'outline' => ['bỏ']]]],
            'terminology' => ['preferred' => [['term' => 'ABC', 'definition' => 'nghĩa', 'avoid' => ['xyz']], ['term' => '']], 'abbreviations' => ['AB']],
            'reasoning' => ['rules' => ['Nêu nguồn']],
            'rubric_notes' => 'Ghi chú <i>riêng</i>',
            'exemplars' => [['title' => 'Mẫu 1', 'text_or_url' => 'Nội dung <img src=x onerror=alert(1)> mẫu', 'note' => '']],
        ], $admin);

        $this->assertSame('Ấm áp', $p->voice['tone']);
        $this->assertSame(['Rõ ràng'], $p->voice['do']);
        $this->assertStringNotContainsString('<script', json_encode($p->voice, JSON_UNESCAPED_UNICODE));
        $this->assertCount(1, $p->structure['article_types']);
        $this->assertCount(1, $p->terminology['preferred']);
        $this->assertStringNotContainsString('<img', $p->exemplars[0]['text_or_url']);
        $this->assertStringNotContainsString('<i>', $p->rubric_notes);
        [$filled, $total] = $p->completeness();
        $this->assertSame(11, $total);
        $this->assertSame(11, $filled);

        $six = array_fill(0, 6, ['title' => 'm', 'text_or_url' => 'x']);
        try {
            $svc->saveProfile($brain, ['exemplars' => $six], $admin);
            $this->fail('phải từ chối hơn 5 bài mẫu');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('tối đa 5', $e->getMessage());
        }
        $this->assertCount(1, $brain->fresh()->profile->exemplars, 'không lưu một phần khi bị từ chối');
        $this->assertSame(1, BrainProfile::where('brain_id', $brain->id)->count());
    }

    public function test_cai_dat_luu_tru_va_phan_quyen(): void
    {
        $admin = $this->user('admin');
        $brain = (new BrainService())->createFromTemplate('Thử', 'trong', [], $admin);
        $svc = new BrainService();
        $svc->saveSettings($brain, 'must_cite', 'high', $admin);
        $this->assertSame('must_cite', $brain->fresh()->reliance());
        $svc->setArchived($brain, true, $admin);
        $this->assertSame('archived', $brain->fresh()->status);
        $svc->setArchived($brain, false, $admin);
        $this->assertTrue($brain->fresh()->isActive());
        try {
            $svc->saveSettings($brain, 'bậy', 'high', $admin);
            $this->fail();
        } catch (\InvalidArgumentException) {
            $this->assertSame('must_cite', $brain->fresh()->reliance());
        }
        $writer = $this->user('writer');
        foreach ([fn () => $svc->saveSettings($brain, 'free', 'low', $writer), fn () => $svc->setArchived($brain, true, $writer), fn () => $svc->saveProfile($brain, [], $writer)] as $try) {
            try {
                $try();
                $this->fail('biên tập viên không được sửa');
            } catch (KnowledgeError) {
                $this->assertTrue(true);
            }
        }
    }
}
