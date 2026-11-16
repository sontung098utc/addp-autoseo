<?php

namespace Tests\Feature\Knowledge;

use App\Models\Article;
use App\Models\Brain;
use App\Models\KnowledgeItem;
use App\Models\Website;
use App\Services\Knowledge\KnowledgeContext;
use App\Services\Knowledge\KnowledgeDedupe;
use App\Services\Knowledge\KnowledgeRetriever;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Ngưỡng liên quan: từ khóa lạc đề không bị nhồi mục kho không liên quan; từ khóa đúng chủ đề giữ nguyên. */
class RelevanceThresholdTest extends TestCase
{
    use RefreshDatabase;

    private Website $site;

    private Brain $brain;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = Website::create(['name' => 'Y', 'cms_type' => 'wordpress', 'base_url' => 'https://y.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $this->brain = Brain::create(['name' => 'ĐTĐ', 'slug' => 'r-'.uniqid(), 'status' => 'active', 'settings' => ['ai_reliance' => 'must_cite', 'risk_class' => 'high']]);
        $this->brain->websites()->attach($this->site->id, ['priority' => 10]);
        foreach ([
            ['Biến chứng của đái tháo đường', 'Đường huyết cao kéo dài gây biến chứng tim mạch, thận, mắt và thần kinh ở người đái tháo đường.'],
            ['Xét nghiệm HbA1c', 'HbA1c phản ánh đường huyết trung bình trong ba tháng và dùng để chẩn đoán đái tháo đường mỗi ngày theo dõi.'],
            ['Hạ đường huyết', 'Hạ đường huyết là biến chứng thường gặp khi dùng thuốc điều trị đái tháo đường, cần xử lý mỗi ngày kịp thời.'],
            ['Ăn uống cho người đái tháo đường', 'Chế độ ăn hợp lý giúp kiểm soát đường huyết, hạn chế đường và tinh bột tinh chế mỗi ngày.'],
            ['Tiêm insulin', 'Người đái tháo đường tuýp 1 cần tiêm insulin mỗi ngày theo chỉ định của bác sĩ.'],
            ['Theo dõi đường huyết tại nhà', 'Nên đo đường huyết tại nhà mỗi ngày và ghi vào sổ theo dõi để bác sĩ điều chỉnh.'],
        ] as [$t, $b]) {
            KnowledgeItem::create(['brain_id' => $this->brain->id, 'type' => 'fact', 'title' => $t, 'body' => $b, 'status' => 'approved', 'version' => 1, 'content_hash' => KnowledgeDedupe::hash($t, $b)]);
        }
    }

    public function test_off_topic_keyword_drops_loosely_matching_items_and_on_topic_keeps_them(): void
    {
        $r = new KnowledgeRetriever;
        $ids = [$this->brain->id];

        config(['knowledge.min_relevance' => 0.0]);
        $this->assertNotEmpty($r->search($this->site, 'lợi ích của việc đi bộ mỗi ngày', 12, $ids), 'không có ngưỡng: mục khớp lỏng vì "mỗi ngày" vẫn bị đưa vào');

        config(['knowledge.min_relevance' => 0.2]);
        $this->assertSame([], $r->search($this->site, 'lợi ích của việc đi bộ mỗi ngày', 12, $ids), 'có ngưỡng: từ khóa lạc đề không nhận mục nào');
        $on = $r->search($this->site, 'biến chứng của bệnh đái tháo đường', 12, $ids);
        $this->assertNotEmpty($on);
        $this->assertSame('Biến chứng của đái tháo đường', $on[0]['title']);
    }

    public function test_off_topic_article_gets_knowledge_gap_instead_of_forced_items(): void
    {
        config(['knowledge.min_relevance' => 0.2]);
        $a = Article::create(['website_id' => $this->site->id, 'primary_keyword' => 'lợi ích của việc đi bộ mỗi ngày', 'workflow_status' => 'planned']);
        $ctx = (new KnowledgeContext)->scopeToBrains([$this->brain->id]);
        $ctx->load($a, [['level' => 'H2', 'heading' => 'Đi bộ đúng cách']]);

        $this->assertSame([], $ctx->options()['knowledge'] ?? []);
        $this->assertTrue($ctx->stepInfo()[0]['knowledge']['gap']);
    }
}
