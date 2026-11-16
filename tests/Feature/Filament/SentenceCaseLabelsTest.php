<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\AgentRunResource;
use App\Filament\Resources\ArticleResource;
use App\Filament\Resources\ArticleVersionResource;
use App\Filament\Resources\CampaignResource;
use App\Filament\Resources\MediaAssetResource;
use App\Filament\Resources\PublicationResource;
use App\Filament\Resources\WebsiteResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Gói B #9: nhãn/breadcrumb theo quy ước câu thường, không bị Filament tự Viết Hoa Từng Chữ. */
class SentenceCaseLabelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_resource_labels_are_not_title_cased(): void
    {
        foreach ([AgentRunResource::class, ArticleResource::class, ArticleVersionResource::class, CampaignResource::class, MediaAssetResource::class, PublicationResource::class, WebsiteResource::class] as $r) {
            $this->assertSame($r::getPluralModelLabel(), $r::getBreadcrumb(), $r);
            $this->assertSame($r::getModelLabel(), $r::getTitleCaseModelLabel(), $r);
            $this->assertSame($r::getPluralModelLabel(), $r::getTitleCasePluralModelLabel(), $r);
        }
    }

    public function test_campaign_list_page_shows_sentence_case(): void
    {
        $u = User::create(['name' => 'a', 'email' => 'a@x.vn', 'password' => 'secret123', 'role' => 'approver', 'is_active' => true]);
        $html = $this->actingAs($u)->get('/admin/doi-ai/chien-dich')->assertOk()->getContent();
        $this->assertStringContainsString('Chiến dịch tự động', $html);
        $this->assertStringNotContainsString('Chiến Dịch Tự Động', $html);
    }
}
