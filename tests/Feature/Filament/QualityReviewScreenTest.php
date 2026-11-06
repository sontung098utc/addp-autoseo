<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ArticleResource\Pages\ListArticles;
use App\Filament\Resources\ArticleVersionResource\Pages\ViewArticleVersion;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;
use Tests\Unit\QualityFixtures;

class QualityReviewScreenTest extends TestCase
{
    use RefreshDatabase;

    private Website $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
    }

    private function user(string $role): User
    {
        return User::create(['name' => $role, 'email' => uniqid() . '@x.vn', 'password' => 'secret123', 'role' => $role, 'is_active' => true]);
    }

    private function make(bool $bad): ArticleVersion
    {
        $a = Article::create(['website_id' => $this->site->id, 'primary_keyword' => 'học bổng toàn phần', 'workflow_status' => 'needs_review']);

        return QualityFixtures::version($a, 1, 'needs_review', $bad ? ['html_content' => '<p>Quá ngắn.</p>'] : []);
    }

    public function test_block_shows_real_counts_and_measurements_for_bad_article(): void
    {
        $this->actingAs($this->user('approver'));
        $v = $this->make(true);
        Livewire::test(ViewArticleVersion::class, ['record' => $v->id])
            ->assertSee('P0 chưa đạt: 3')->assertSee('Còn 3 lỗi P0')
            ->assertSee('LEN01')->assertSee('Đo được: 2 từ · Ngưỡng: ≥ 800 từ')
            ->assertSee('HEAD01')->assertSee('KW01');
    }

    public function test_block_for_clean_article_has_zero_failures(): void
    {
        $this->actingAs($this->user('approver'));
        $v = $this->make(false);
        Livewire::test(ViewArticleVersion::class, ['record' => $v->id])
            ->assertSee('P0 chưa đạt: 0')->assertSee('P1 chưa đạt: 0')->assertSee('P2 chưa đạt: 0')
            ->assertSee('Không có mục nào chưa đạt')->assertDontSee('chặn duyệt');
    }

    public function test_approve_action_blocked_with_p0_requires_override_reason(): void
    {
        $this->actingAs($this->user('approver'));
        $v = $this->make(true);
        Livewire::test(ViewArticleVersion::class, ['record' => $v->id])
            ->callAction('approve', ['note' => ''])
            ->assertHasActionErrors(['override_reason' => 'required']);
        Livewire::test(ViewArticleVersion::class, ['record' => $v->id])
            ->callAction('approve', ['override_reason' => 'ngắn'])
            ->assertHasActionErrors(['override_reason']);
        $this->assertSame('needs_review', $v->fresh()->review_status);

        Livewire::test(ViewArticleVersion::class, ['record' => $v->id])
            ->callAction('approve', ['override_reason' => 'Thông báo ngắn, đã thống nhất với biên tập'])
            ->assertHasNoActionErrors();
        $this->assertSame('approved', $v->fresh()->review_status);
    }

    public function test_approve_action_without_p0_keeps_old_modal(): void
    {
        $this->actingAs($this->user('approver'));
        $v = $this->make(false);
        Livewire::test(ViewArticleVersion::class, ['record' => $v->id])
            ->callAction('approve', ['note' => 'ok'])->assertHasNoActionErrors();
        $this->assertSame('approved', $v->fresh()->review_status);
    }

    public function test_list_approve_is_blocked_too(): void
    {
        $this->actingAs($this->user('approver'));
        $v = $this->make(true);
        Livewire::test(ListArticles::class)->callTableAction('approve', $v->article, ['note' => 'ok'])
            ->assertHasTableActionErrors(['override_reason' => 'required']);
        $this->assertSame('needs_review', $v->article->fresh()->workflow_status);
        Livewire::test(ListArticles::class)->callTableAction('approve', $v->article, ['override_reason' => 'Đã xác nhận với trưởng nhóm nội dung'])
            ->assertHasNoTableActionErrors();
        $this->assertSame('approved', $v->article->fresh()->workflow_status);
    }

    public function test_writer_cannot_approve_even_with_reason(): void
    {
        $this->actingAs($this->user('writer'));
        $v = $this->make(true);
        Livewire::test(ViewArticleVersion::class, ['record' => $v->id])->assertActionHidden('approve');
        $this->assertSame('needs_review', $v->fresh()->review_status);
    }
}
