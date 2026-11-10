<?php

namespace App\Services\Knowledge;

use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\KnowledgeGap;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeItemUsage;
use Illuminate\Support\Facades\Log;

/**
 * AG-S20: cầu nối giữa AgentRunOrchestrator và kho kiến thức (tách riêng để orchestrator chỉ gọi 3 hàm).
 * Truy xuất MỘT lần mỗi lượt chạy (vòng sửa dùng lại cùng bộ atom). Lỗi kho không làm hỏng lượt chạy.
 */
final class KnowledgeContext
{
    public const LIMIT = 5;

    public const BODY_MAX = 600;

    /** @var list<array{id:int,version:int,title:string,body:string,source:?string}>|null */
    private ?array $items = null;

    private bool $gap = false;

    /** AG-B3: quy tắc chuyên gia (quan niệm sai, không được nói) áp cho mọi bài của phạm vi; nạp riêng, không qua truy xuất theo chủ đề. @var list<array<string,mixed>> */
    private array $rules = [];

    /** AG-B1: phạm vi Trí tuệ của lượt chạy (null = như cũ, không lọc). */
    private ?array $brainIds = null;

    public function __construct(private KnowledgeRetriever $retriever = new KnowledgeRetriever())
    {
    }

    /** AG-B1: chỉ truy xuất atom thuộc các Trí tuệ này (cộng atom brain_id NULL theo website). Gọi trước load(). @param list<int> $ids */
    public function scopeToBrains(array $ids): self
    {
        $this->brainIds = array_values(array_map('intval', $ids));

        return $this;
    }

    /** @param list<string|mixed> $outline */
    public function load(Article $article, array $outline): void
    {
        if ($this->items !== null) {
            return;
        }
        $this->items = [];
        try {
            $site = $article->website;
            if (! $site) {
                return;
            }
            $this->rules = $this->retriever->rules($site, $this->brainIds);
            $query = $article->primary_keyword.' '.implode(' ', array_map(fn ($o) => is_scalar($o) ? (string) $o : '', array_slice($outline, 0, 12)));
            foreach ($this->retriever->search($site, $query, self::LIMIT, $this->brainIds) as $r) {
                $this->items[] = ['id' => $r['id'], 'version' => $r['version'], 'title' => $r['title'], 'body' => mb_substr($r['body'], 0, self::BODY_MAX), 'source' => $r['source']];
            }
            if ($this->items === []) {
                $this->gap = true;
                $this->recordGap($article, mb_substr(trim((string) $article->primary_keyword), 0, 190), $this->brainIds[0] ?? null);
            }
        } catch (\Throwable $e) {
            Log::warning('Kho kiến thức: truy xuất lỗi, bỏ qua.', ['error' => get_class($e)]);
            $this->items = [];
        }
    }

    /** @return array<string,mixed> options cho adapter AI (rỗng nếu không có atom) */
    public function options(): array
    {
        return ($this->items ? ['knowledge' => $this->items] : []) + ($this->rules ? ['knowledge_rules' => $this->rules] : []);
    }

    /** Dữ liệu ghi vào bước 'write' và đoạn ghi chú ngắn. @return array{0:array<string,mixed>,1:string} */
    public function stepInfo(): array
    {
        $ids = array_column($this->items ?? [], 'id');
        $data = ['knowledge' => ['count' => count($ids), 'ids' => $ids, 'gap' => $this->gap] + ($this->rules ? ['rule_ids' => array_column($this->rules, 'id')] : [])];
        $note = $ids !== [] ? ' Dùng '.count($ids).' mục kiến thức đã duyệt (KB#'.implode(', KB#', $ids).').'
            : ($this->gap ? ' Kho kiến thức chưa có mục đã duyệt phù hợp; đã ghi khoảng trống kiến thức để bổ sung.' : '');
        if ($this->rules) {
            $note .= ' Áp '.count($this->rules).' quy tắc của chuyên gia (quan niệm sai / không được nói): KB#'.implode(', KB#', array_column($this->rules, 'id')).'.';
        }

        return [$data, $note];
    }

    /** Sau khi lưu bản bài: ghi mục nào ở phiên bản nào đã được đưa vào prompt. */
    public function recordUsage(ArticleVersion $version): void
    {
        try {
            foreach (array_merge($this->items ?? [], $this->rules) as $i) {
                KnowledgeItemUsage::updateOrCreate(['article_version_id' => $version->id, 'item_id' => $i['id']], ['item_version' => $i['version']]);
                KnowledgeItem::whereKey($i['id'])->update(['last_used_at' => now()]);
            }
        } catch (\Throwable $e) {
            Log::warning('Kho kiến thức: ghi usage lỗi.', ['error' => get_class($e)]);
        }
    }

    private function recordGap(Article $article, string $query, ?int $brainId = null): void
    {
        if ($query === '') {
            return;
        }
        $gap = KnowledgeGap::firstOrCreate(['website_id' => $article->website_id, 'query' => $query], ['article_id' => $article->id, 'count' => 0]);
        // AG-S21: tìm vẫn không ra dù từng "đã bổ sung" (mục bị cũ/loại, hoặc chưa đủ khớp) → mở lại.
        $gap->update(['count' => $gap->count + 1, 'article_id' => $article->id, 'brain_id' => $gap->brain_id ?? $brainId, 'status' => 'open', 'filled_at' => null, 'filled_item_id' => null]);
    }
}
