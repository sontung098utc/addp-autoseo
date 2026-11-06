<?php

namespace Tests\Feature;

use App\Filament\Support\Demo\DemoMode;
use App\Filament\Support\Demo\DemoStore;
use App\Filament\Support\Planning\DuplicateKeywordException;
use App\Filament\Support\Planning\PlannedArticleCreator;
use App\Models\Article;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class PlannedArticleCreatorTest extends TestCase
{
    use RefreshDatabase;

    private function site(string $status = 'active'): Website
    {
        return Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://'.uniqid().'.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => $status]);
    }

    private function user(string $role, bool $active = true): User
    {
        return User::create(['name' => $role, 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => $role, 'is_active' => $active]);
    }

    public function test_tao_bai_that_trang_thai_planned_va_ghi_nguoi_tao(): void
    {
        $u = $this->user('writer');
        $a = (new PlannedArticleCreator())->create($u, $this->site(), '  Học bổng   toàn phần ', 'Học bổng', 'commercial', ['hb 2027', ' ']);

        $this->assertSame('planned', $a->workflow_status);
        $this->assertSame($u->id, $a->created_by);
        $this->assertSame('Học bổng toàn phần', $a->primary_keyword);
        $this->assertSame(['hb 2027'], $a->secondary_keywords);
        $this->assertDatabaseCount('articles', 1);
    }

    public function test_trung_tu_khoa_bi_chan_khong_phan_biet_hoa_thuong_va_khoang_trang(): void
    {
        $site = $this->site();
        $c = new PlannedArticleCreator();
        $first = $c->create($this->user('writer'), $site, 'Học bổng toàn phần');

        try {
            $c->create($this->user('writer'), $site, '  HỌC BỔNG   toàn phần');
            $this->fail('Phải ném DuplicateKeywordException');
        } catch (DuplicateKeywordException $e) {
            $this->assertSame($first->id, $e->existingArticleId);
        }
        $this->assertDatabaseCount('articles', 1);
    }

    public function test_cung_tu_khoa_khac_website_duoc_phep(): void
    {
        $c = new PlannedArticleCreator();
        $c->create($this->user('writer'), $this->site(), 'kw');
        $other = Website::create(['name' => 'S2', 'cms_type' => 'wordpress', 'base_url' => 'https://b.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $c->create($this->user('writer'), $other, 'kw');
        $this->assertDatabaseCount('articles', 2);
    }

    public function test_chan_quyen_website_khong_active_va_du_lieu_sai(): void
    {
        $c = new PlannedArticleCreator();
        foreach ([
            fn () => $c->create($this->user('writer', false), $this->site(), 'a'),
            fn () => $c->create($this->user('writer'), $this->site('revoked'), 'a'),
            fn () => $c->create($this->user('writer'), $this->site(), '   '),
            fn () => $c->create($this->user('writer'), $this->site(), 'a', null, 'bậy'),
        ] as $case) {
            try {
                $case();
                $this->fail('Phải bị chặn');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame(0, Article::count());
    }

    public function test_che_do_minh_hoa_tat_o_production_va_state_chi_nhan_gia_tri_hop_le(): void
    {
        $this->assertTrue(DemoMode::enabled());
        $this->assertSame('loi', DemoMode::state('loi', ['loi', 'rong'], 'ok'));
        $this->assertSame('ok', DemoMode::state('bậy', ['loi', 'rong'], 'ok'));

        DemoStore::put('t.k', 5);
        $this->assertSame(5, DemoStore::get('t.k'));
        DemoStore::forget('t.k');

        $this->app['env'] = 'production';
        $this->assertFalse(DemoMode::enabled());
        $this->assertSame('ok', DemoMode::state('loi', ['loi', 'rong'], 'ok'));
        DemoStore::put('t.k', 9); // production: bỏ qua
        $this->assertNull(DemoStore::get('t.k'));
    }
}
