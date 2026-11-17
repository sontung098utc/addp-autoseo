<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ArticleResource;
use App\Filament\Resources\ArticleResource\Pages\ViewArticle;
use App\Filament\Support\Review\ReviewWorkflow;
use App\Jobs\PublishArticleJob;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\Publication;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Sau một lượt đăng lỗi (vd. mã cửa hàng Magento sai) bài vẫn "Đã lên lịch" nên nút Xếp lịch biến mất và không có đường nào đăng lại.
 * Nay: xếp lịch lại lượt lỗi, "Đăng ngay" cho lượt đã có và cho bài đã duyệt. Mọi nút giữ khóa publication_key nên không đăng trùng.
 */
class ScheduleRetryPublishNowTest extends TestCase
{
    use RefreshDatabase;

    private Website $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = Website::create(['name' => 'ADDP', 'cms_type' => 'magento2', 'base_url' => 'https://addp.vn', 'encrypted_credentials' => ['k' => 'x'], 'status' => 'active']);
    }

    private function user(string $role): User
    {
        return User::create(['name' => $role, 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => $role, 'is_active' => true]);
    }

    private function approved(string $status = 'approved'): array
    {
        $a = Article::create(['website_id' => $this->site->id, 'primary_keyword' => 'đo đường huyết', 'workflow_status' => $status]);
        $v = ArticleVersion::create(['article_id' => $a->id, 'version' => 1, 'title' => 't', 'slug' => 's', 'html_content' => '<p>x</p>', 'review_status' => 'approved']);

        return [$a, $v];
    }

    private function failedPublication(): Publication
    {
        [$a, $v] = $this->approved('scheduled');

        return Publication::create(['website_id' => $this->site->id, 'article_version_id' => $v->id, 'publication_key' => 'pub-k-1', 'scheduled_at_utc' => now()->subDay(),
            'timezone' => 'Asia/Ho_Chi_Minh', 'status' => 'failed', 'last_error' => '[ERR06] HTTP 400', 'attempt_count' => 1]);
    }

    public function test_publish_now_tao_luot_dang_den_han_ngay_va_khong_tao_trung(): void
    {
        $admin = $this->user('admin');
        [$a, $v] = $this->approved();

        $p = (new ReviewWorkflow)->publishNow($admin, $v, $this->site);

        $this->assertSame('scheduled', $p->status);
        $this->assertTrue($p->scheduled_at_utc->lessThanOrEqualTo(now('UTC')->addSecond()), 'Đến hạn ngay.');
        $this->assertSame('scheduled', $a->fresh()->workflow_status);
        $this->expectException(InvalidArgumentException::class);
        (new ReviewWorkflow)->publishNow($admin, $v->fresh(), $this->site); // đã có lượt đăng cho bản này
    }

    public function test_publish_now_chi_cho_ban_da_duyet_va_nguoi_duyet(): void
    {
        [, $v] = $this->approved('needs_review');
        try {
            (new ReviewWorkflow)->publishNow($this->user('admin'), $v, $this->site);
            $this->fail('Bài chưa duyệt không được đăng.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('đã duyệt', $e->getMessage());
        }
        [, $v2] = $this->approved();
        $this->expectException(InvalidArgumentException::class);
        (new ReviewWorkflow)->publishNow($this->user('writer'), $v2, $this->site);
    }

    public function test_trang_bai_da_duyet_co_dang_ngay_va_bam_la_chay_job_dang_bai(): void
    {
        Bus::fake();
        $this->actingAs($this->user('approver'));
        [$a] = $this->approved();

        Livewire::test(ViewArticle::class, ['record' => $a->id])
            ->assertActionVisible('schedule')
            ->assertActionVisible('publish_now')
            ->callAction('publish_now', ['website_id' => $this->site->id]);

        Bus::assertDispatchedSync(PublishArticleJob::class);
        $this->assertSame(1, Publication::count());
        $this->assertSame('pub-'.$this->site->id.'-a'.$a->id.'-v1', Publication::first()->publication_key);
    }

    public function test_bai_chua_duyet_khong_co_nut_dang_ngay(): void
    {
        $this->actingAs($this->user('approver'));
        [$a] = $this->approved('needs_review');
        Livewire::test(ViewArticle::class, ['record' => $a->id])->assertActionHidden('publish_now')->assertActionHidden('schedule');
    }

    public function test_bang_bai_viet_co_dang_ngay_trong_menu_cho_bai_da_duyet(): void
    {
        Bus::fake();
        $this->actingAs($this->user('approver'));
        [$a] = $this->approved();
        Livewire::test(ArticleResource\Pages\ListArticles::class)->assertTableActionVisible('publish_now', $a)
            ->callTableAction('publish_now', $a, ['website_id' => $this->site->id]);
        Bus::assertDispatchedSync(PublishArticleJob::class);
    }

    public function test_luot_loi_o_trang_bai_co_xep_lich_lai_va_dang_ngay(): void
    {
        $this->actingAs($this->user('approver'));
        $p = $this->failedPublication();
        $articleId = $p->articleVersion->article_id;

        $html = Livewire::test(ViewArticle::class, ['record' => $articleId])->html();
        $this->assertStringContainsString('data-testid="aw-pub-reschedule"', $html);
        $this->assertStringContainsString('Xếp lịch lại', $html);
        $this->assertStringContainsString('data-testid="aw-pub-now"', $html);

        $when = now('Asia/Ho_Chi_Minh')->addDay()->setTime(9, 0)->format('Y-m-d H:i:s');
        Livewire::test(ViewArticle::class, ['record' => $articleId])->callAction('reschedulePublication', ['scheduled_at_vn' => $when], ['id' => $p->id]);
        $p->refresh();
        $this->assertSame('scheduled', $p->status);
        $this->assertNull($p->last_error);
        $this->assertSame('pub-k-1', $p->publication_key);
    }

    public function test_dang_ngay_luot_loi_chay_job_va_chi_voi_luot_scheduled_hoac_failed(): void
    {
        Bus::fake();
        $this->actingAs($this->user('approver'));
        $p = $this->failedPublication();
        $articleId = $p->articleVersion->article_id;

        Livewire::test(ViewArticle::class, ['record' => $articleId])->callAction('publishNowPublication', [], ['id' => $p->id]);
        Bus::assertDispatchedSync(PublishArticleJob::class);

        Bus::fake();
        $p->update(['status' => 'needs_reconcile']);
        Livewire::test(ViewArticle::class, ['record' => $articleId])->callAction('publishNowPublication', [], ['id' => $p->id]);
        Bus::assertNotDispatchedSync(PublishArticleJob::class); // chờ đối soát: không đăng lại
    }

    public function test_nguoi_viet_khong_thay_nut_dang_ngay_hoac_xep_lich_lai(): void
    {
        $this->actingAs($this->user('writer'));
        $p = $this->failedPublication();
        $html = Livewire::test(ViewArticle::class, ['record' => $p->articleVersion->article_id])->html();
        $this->assertStringNotContainsString('data-testid="aw-pub-now"', $html);
        $this->assertStringNotContainsString('data-testid="aw-pub-reschedule"', $html);
    }
}
