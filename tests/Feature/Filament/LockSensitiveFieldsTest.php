<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ArticleResource;
use App\Filament\Resources\ArticleResource\Pages as ArticlePages;
use App\Filament\Resources\PublicationResource;
use App\Filament\Resources\PublicationResource\Pages as PublicationPages;
use App\Filament\Resources\WebsiteResource;
use App\Filament\Resources\WebsiteResource\Pages as WebsitePages;
use App\Models\Article;
use App\Models\Publication;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LockSensitiveFieldsTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'S3CRET-TEST-VALUE';

    private function user(string $role, bool $active = true): User
    {
        return User::create([
            'name' => $role, 'email' => $role.($active ? '' : '-off').'@test.local',
            'password' => 'test-password-123', 'role' => $role, 'is_active' => $active,
        ]);
    }

    private function website(): Website
    {
        return Website::create([
            'name' => 'Site thử', 'cms_type' => 'wordpress', 'base_url' => 'https://example.test',
            'store_scope' => 'default', 'status' => 'active',
            'encrypted_credentials' => ['application_password' => self::SECRET],
        ]);
    }

    private function article(Website $w): Article
    {
        return Article::create([
            'website_id' => $w->id, 'primary_keyword' => 'từ khóa', 'search_intent' => 'informational',
        ]);
    }

    private function publication(Website $w): Publication
    {
        $v = \App\Models\ArticleVersion::create([
            'article_id' => $this->article($w)->id, 'version' => 1, 'title' => 'T', 'slug' => 't', 'html_content' => '<p>x</p>',
        ]);

        return Publication::create([
            'website_id' => $w->id, 'article_version_id' => $v->id, 'publication_key' => 'key-1',
            'scheduled_at_utc' => now(), 'timezone' => 'Asia/Ho_Chi_Minh', 'status' => 'scheduled',
        ]);
    }

    public function test_article_form_has_no_workflow_status_and_ignores_it(): void
    {
        $this->actingAs($this->user('writer'));
        $a = $this->article($this->website());

        Livewire::test(ArticlePages\EditArticle::class, ['record' => $a->getRouteKey()])
            ->assertFormFieldDoesNotExist('workflow_status')
            ->fillForm(['primary_keyword' => 'mới'])
            ->set('data.workflow_status', 'approved')
            ->call('save');

        $a->refresh();
        $this->assertSame('mới', $a->primary_keyword);
        $this->assertSame('planned', $a->workflow_status);
    }

    public function test_article_create_ignores_workflow_status(): void
    {
        $this->actingAs($this->user('writer'));
        $w = $this->website();

        Livewire::test(ArticlePages\CreateArticle::class)
            ->fillForm(['website_id' => $w->id, 'primary_keyword' => 'kw', 'search_intent' => 'informational'])
            ->set('data.workflow_status', 'published')
            ->call('create');

        $this->assertSame('planned', Article::where('primary_keyword', 'kw')->firstOrFail()->workflow_status);
    }

    public function test_publication_is_read_only(): void
    {
        $this->actingAs($this->user('approver'));
        $p = $this->publication($this->website());

        $this->assertFalse(PublicationResource::canCreate());
        $this->assertFalse(PublicationResource::canEdit($p));
        $this->assertFalse(PublicationResource::canDelete($p));
        $this->assertArrayNotHasKey('edit', PublicationResource::getPages());
        $this->assertArrayNotHasKey('create', PublicationResource::getPages());

        Livewire::test(PublicationPages\ListPublications::class)->assertSee('key-1');
        $this->get(PublicationResource::getUrl('view', ['record' => $p]))->assertOk();
        $this->assertSame('key-1', $p->fresh()->publication_key);
    }

    public function test_website_edit_does_not_leak_credentials_and_keeps_them(): void
    {
        $this->actingAs($this->user('admin'));
        $w = $this->website();

        $html = Livewire::test(WebsitePages\EditWebsite::class, ['record' => $w->getRouteKey()])
            ->assertSee('•••• đã lưu')
            ->assertDontSee(self::SECRET)
            ->fillForm(['name' => 'Tên mới'])
            ->set('data.encrypted_credentials', ['application_password' => 'HACKED'])
            ->call('save')
            ->html();

        $this->assertStringNotContainsString(self::SECRET, $html);
        $w->refresh();
        $this->assertSame('Tên mới', $w->name);
        $this->assertSame(self::SECRET, $w->encrypted_credentials['application_password']);
    }

    public function test_website_create_stores_encrypted_credentials(): void
    {
        $this->actingAs($this->user('admin'));

        Livewire::test(WebsitePages\CreateWebsite::class)
            ->fillForm([
                'name' => 'Mới', 'cms_type' => 'wordpress', 'base_url' => 'https://new.test',
                'store_scope' => 'default', 'status' => 'active',
                'credentials_input' => ['username' => 'bot', 'application_password' => self::SECRET],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $w = Website::where('name', 'Mới')->firstOrFail();
        $this->assertSame(self::SECRET, $w->encrypted_credentials['application_password']);
        $this->assertNotSame(self::SECRET, $w->getRawOriginal('encrypted_credentials'));
    }

    public function test_website_cannot_be_deleted(): void
    {
        $this->actingAs($this->user('admin'));
        $w = $this->website();

        $this->assertFalse(WebsiteResource::canDelete($w));
        $this->assertFalse(WebsiteResource::canDeleteAny());
    }

    public function test_role_matrix(): void
    {
        $w = $this->website();
        $a = $this->article($w);
        $p = $this->publication($w);

        // [role => [website, articleView, articleEdit, articleDelete, publicationView]]
        $matrix = [
            'admin' => [true, true, true, true, true],
            'approver' => [false, true, true, false, true],
            'writer' => [false, true, true, false, false],
        ];
        foreach ($matrix as $role => [$web, $av, $ae, $ad, $pv]) {
            $this->actingAs($this->user($role));
            $this->assertSame($web, WebsiteResource::canViewAny(), "$role website view");
            $this->assertSame($web, WebsiteResource::canCreate(), "$role website create");
            $this->assertSame($web, WebsiteResource::canEdit($w), "$role website edit");
            $this->assertSame($av, ArticleResource::canViewAny(), "$role article view");
            $this->assertSame($ae, ArticleResource::canEdit($a), "$role article edit");
            $this->assertSame($ad, ArticleResource::canDelete($a), "$role article delete");
            $this->assertSame($pv, PublicationResource::canViewAny(), "$role publication view");
        }
    }

    public function test_writer_gets_403_on_website_and_publication_pages(): void
    {
        $this->actingAs($this->user('writer'));
        $w = $this->website();
        $p = $this->publication($w);

        $this->get(WebsiteResource::getUrl('index'))->assertForbidden();
        $this->get(WebsiteResource::getUrl('edit', ['record' => $w]))->assertForbidden();
        $this->get(PublicationResource::getUrl('index'))->assertForbidden();
        $this->get(PublicationResource::getUrl('view', ['record' => $p]))->assertForbidden();
    }

    public function test_inactive_user_is_blocked(): void
    {
        $this->actingAs($this->user('admin', false));

        $this->assertFalse(WebsiteResource::canViewAny());
        $this->assertFalse(ArticleResource::canViewAny());
        $this->assertFalse(PublicationResource::canViewAny());
        $this->get(WebsiteResource::getUrl('index'))->assertForbidden();
    }
}
