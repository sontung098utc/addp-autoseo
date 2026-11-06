<?php

namespace Tests\Unit;

use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\Publication;
use App\Models\User;
use App\Models\Website;
use App\Policies\ArticlePolicy;
use App\Policies\ArticleVersionPolicy;
use App\Policies\PublicationPolicy;
use App\Policies\WebsitePolicy;
use PHPUnit\Framework\TestCase;

class PoliciesTest extends TestCase
{
    protected User $admin;
    protected User $approver;
    protected User $writer;
    protected User $inactive;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = new User(['role' => 'admin', 'is_active' => true]);
        $this->approver = new User(['role' => 'approver', 'is_active' => true]);
        $this->writer = new User(['role' => 'writer', 'is_active' => true]);
        $this->inactive = new User(['role' => 'admin', 'is_active' => false]);
    }

    public function test_website_policy(): void
    {
        $policy = new WebsitePolicy();
        $website = new Website();

        $this->assertTrue($policy->viewAny($this->admin));
        $this->assertFalse($policy->viewAny($this->approver));
        $this->assertFalse($policy->viewAny($this->writer));
        $this->assertFalse($policy->viewAny($this->inactive));

        $this->assertFalse($policy->delete($this->admin, $website));
    }

    public function test_article_policy(): void
    {
        $policy = new ArticlePolicy();
        $article = new Article();

        $this->assertTrue($policy->viewAny($this->admin));
        $this->assertTrue($policy->viewAny($this->approver));
        $this->assertTrue($policy->viewAny($this->writer));
        $this->assertFalse($policy->viewAny($this->inactive));

        $this->assertTrue($policy->delete($this->admin, $article));
        $this->assertFalse($policy->delete($this->writer, $article));

        $this->assertTrue($policy->review($this->approver, $article));
        $this->assertFalse($policy->review($this->writer, $article));
    }

    public function test_publication_policy(): void
    {
        $policy = new PublicationPolicy();
        $pub = new Publication();

        $this->assertTrue($policy->viewAny($this->admin));
        $this->assertTrue($policy->viewAny($this->approver));
        $this->assertFalse($policy->viewAny($this->writer));
        $this->assertFalse($policy->create($this->admin));
        $this->assertFalse($policy->delete($this->admin, $pub));
    }
}
