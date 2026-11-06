<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ArticleResource\Pages\ListArticles;
use App\Filament\Resources\ArticleVersionResource\Pages\ViewArticleVersion;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\Publication;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class ReviewActionsTest extends TestCase
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

    private function make(string $ws = 'needs_review', string $vs = 'needs_review', ?User $creator = null): ArticleVersion
    {
        $a = Article::create(['website_id' => $this->site->id, 'created_by' => $creator?->id, 'primary_keyword' => 'k', 'workflow_status' => $ws]);

        return ArticleVersion::create(['article_id' => $a->id, 'version' => 1, 'title' => 't', 'slug' => 's', 'html_content' => '<p>x</p>', 'review_status' => $vs]);
    }

    public function test_writer_submits_but_cannot_approve_in_list(): void
    {
        $this->actingAs($this->user('writer'));
        $v = $this->make('planned', 'rejected');
        Livewire::test(ListArticles::class)
            ->callTableAction('submit_review', $v->article)
            ->assertHasNoTableActionErrors();
        $this->assertSame('needs_review', $v->article->fresh()->workflow_status);

        Livewire::test(ListArticles::class)
            ->assertTableActionHidden('approve', $v->article->fresh())
            ->assertTableActionHidden('reject', $v->article->fresh());
        $this->assertSame('needs_review', $v->article->fresh()->workflow_status);
    }

    public function test_approver_approves_and_schedules_from_list(): void
    {
        $this->actingAs($this->user('approver'));
        $v = $this->make();
        Livewire::test(ListArticles::class)->callTableAction('approve', $v->article, ['note' => 'ok'])->assertHasNoTableActionErrors();
        $this->assertSame('approved', $v->article->fresh()->workflow_status);

        $at = Carbon::now('Asia/Ho_Chi_Minh')->addDay()->format('Y-m-d H:i:s');
        Livewire::test(ListArticles::class)->callTableAction('schedule', $v->article->fresh(), ['website_id' => $this->site->id, 'scheduled_at_vn' => $at])->assertHasNoTableActionErrors();
        $this->assertSame(1, Publication::count());
        $this->assertSame('scheduled', $v->article->fresh()->workflow_status);
    }

    public function test_self_approve_without_note_fails_validation(): void
    {
        $me = $this->user('approver');
        $this->actingAs($me);
        $v = $this->make(creator: $me);
        Livewire::test(ListArticles::class)->callTableAction('approve', $v->article, ['note' => ''])->assertHasTableActionErrors(['note' => 'required']);
        $this->assertSame('needs_review', $v->article->fresh()->workflow_status);
    }

    public function test_reject_requires_reason_and_writer_denied_on_version_page(): void
    {
        $this->actingAs($this->user('approver'));
        $v = $this->make();
        Livewire::test(ViewArticleVersion::class, ['record' => $v->id])
            ->callAction('reject', ['reason' => ''])->assertHasActionErrors(['reason' => 'required']);
        Livewire::test(ViewArticleVersion::class, ['record' => $v->id])
            ->callAction('reject', ['reason' => 'Sai số liệu']);
        $this->assertSame('rejected', $v->fresh()->review_status);

        $this->actingAs($this->user('writer'));
        $v2 = $this->make();
        Livewire::test(ViewArticleVersion::class, ['record' => $v2->id])->assertActionHidden('approve')->assertActionHidden('reject');
    }

    public function test_canh_bao_khi_website_chua_co_connector(): void
    {
        // WordPress chưa có connector (task 1.2) => cảnh báo trung thực ở form Xếp lịch.
        $this->assertFalse(\App\Filament\Support\Review\ReviewActions::connectorAvailable($this->site));

        // Khi connector được đăng ký thì không còn cảnh báo.
        $this->app->instance(\App\Contracts\PublisherInterface::class, $this->createMock(\App\Contracts\PublisherInterface::class));
        $this->assertTrue(\App\Filament\Support\Review\ReviewActions::connectorAvailable($this->site));
    }
}
