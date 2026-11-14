<?php

namespace Tests\Feature\Agent;

use App\Models\Brain;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeTopic;
use App\Models\Website;
use App\Services\Agent\Run\AgentRunOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Nạp kho theo định hướng: chỉ các mảng cấp một chưa có mục đã duyệt (bỏ nhánh "Từ bài") mới được nạp tiếp, tối đa 2 mảng mỗi lần. */
class EmptyTopicsTest extends TestCase
{
    use RefreshDatabase;

    public function test_chi_lay_mang_dinh_huong_con_trong_toi_da_hai_va_bo_nhanh_tu_bai(): void
    {
        $site = Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $brain = Brain::create(['name' => 'CN dược', 'slug' => 'cn-duoc', 'status' => 'active', 'language' => 'vi']);
        $mk = fn (string $n, ?int $p = null) => KnowledgeTopic::create(['brain_id' => $brain->id, 'name' => $n, 'parent_id' => $p]);
        $full = $mk('Bào chế');
        $child = $mk('Công thức', $full->id);
        $mk('Từ bài «máy dập viên»');
        $mk('GMP và thẩm định');
        $mk('Bao gói');
        $mk('Pháp lý');
        KnowledgeItem::create(['website_id' => $site->id, 'brain_id' => $brain->id, 'topic_id' => $child->id, 'type' => 'fact', 'title' => 'x', 'body' => 'b', 'status' => 'approved', 'version' => 1, 'content_hash' => 'h1']);

        $m = new \ReflectionMethod(AgentRunOrchestrator::class, 'emptyTopics');
        $m->setAccessible(true);
        $o = (new \ReflectionClass(AgentRunOrchestrator::class))->newInstanceWithoutConstructor();
        $this->assertSame(['GMP và thẩm định', 'Bao gói'], $m->invoke($o, $brain->id, $brain->name), 'Mảng có mục (kể cả ở chủ đề con) và nhánh "Từ bài" bị bỏ; tối đa 2 mảng.');
    }
}
