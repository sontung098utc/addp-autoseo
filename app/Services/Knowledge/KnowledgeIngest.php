<?php

namespace App\Services\Knowledge;

use App\Models\KnowledgeItem;
use App\Models\KnowledgeSource;
use App\Models\User;
use App\Models\Website;
use Illuminate\Support\Facades\DB;

/**
 * AG-S20: dán văn bản → lưu nguồn → tách thủ công thành atom 'proposed' bằng QUY TẮC TẤT ĐỊNH (không AI).
 * Quy tắc: tách theo đoạn trống; dòng tiêu đề (bắt đầu bằng #, hoặc dòng ngắn ≤80 ký tự không kết thúc bằng dấu câu và đứng trước thân) mở một mục mới;
 * mục không tiêu đề lấy câu đầu làm tiêu đề. Đoạn < 15 ký tự bị bỏ. Nội dung nguồn chỉ là DỮ LIỆU: không bao giờ được thực thi.
 */
final class KnowledgeIngest
{
    public const MAX_TEXT = 200000;

    public function __construct(private KnowledgeDedupe $dedupe = new KnowledgeDedupe())
    {
    }

    public function paste(string $title, string $text, ?Website $website, ?User $by = null, string $kind = 'paste', ?string $url = null): KnowledgeSource
    {
        $title = trim(mb_substr($title, 0, 200));
        $text = trim(mb_substr(str_replace(["\r\n", "\r"], "\n", $text), 0, self::MAX_TEXT));
        if ($title === '' || $text === '') {
            throw new \InvalidArgumentException('Cần có tiêu đề và nội dung nguồn.');
        }

        return KnowledgeSource::create([
            'website_id' => $website?->id, 'kind' => $kind, 'title' => $title, 'url' => $url, 'raw_text' => $text,
            'content_hash' => KnowledgeDedupe::hash('', $text), 'status' => 'new', 'created_by' => $by?->id,
        ]);
    }

    /** @return list<array{title:string,body:string}> */
    public function parse(string $text): array
    {
        $blocks = preg_split('/\n\s*\n/u', str_replace(["\r\n", "\r"], "\n", $text)) ?: [];
        $atoms = [];
        $pendingTitle = null;
        foreach ($blocks as $block) {
            $lines = array_values(array_filter(array_map('trim', explode("\n", $block)), fn ($l) => $l !== ''));
            if ($lines === []) {
                continue;
            }
            $title = null;
            if ($this->isHeading($lines[0], count($lines) > 1)) {
                $title = ltrim($lines[0], "# \t");
                array_shift($lines);
                $title = rtrim($title, ':');
            }
            $body = trim(implode("\n", $lines));
            if ($body === '') { // khối chỉ có tiêu đề: gắn cho khối sau
                $pendingTitle = $title;

                continue;
            }
            $title ??= $pendingTitle;
            $pendingTitle = null;
            if (mb_strlen($body) < 15) {
                continue;
            }
            $atoms[] = ['title' => mb_substr($title ?? $this->firstSentence($body), 0, 200), 'body' => $body];
        }

        return $atoms;
    }

    /**
     * @return array{created:int,duplicates:int,items:list<KnowledgeItem>}
     */
    public function split(KnowledgeSource $source, ?User $by = null): array
    {
        $created = [];
        $dups = 0;
        DB::transaction(function () use ($source, $by, &$created, &$dups) {
            foreach ($this->parse((string) $source->raw_text) as $a) {
                $item = new KnowledgeItem([
                    'website_id' => $source->website_id, 'source_id' => $source->id, 'type' => 'fact', 'title' => $a['title'], 'body' => $a['body'],
                    'status' => 'proposed', 'created_by' => 'rule', 'version' => 1, 'content_hash' => KnowledgeDedupe::hash($a['title'], $a['body']),
                ]);
                if ($this->dedupe->find($item)['exact']->isNotEmpty()) { // trùng y hệt: không tạo lại
                    $dups++;

                    continue;
                }
                $item->save();
                $item->versions()->create(['version' => 1, 'title' => $item->title, 'body' => $item->body, 'status' => 'proposed', 'changed_by' => $by?->id, 'note' => 'Tách từ nguồn']);
                $created[] = $item;
            }
            $source->update(['status' => 'split']);
        });

        return ['created' => count($created), 'duplicates' => $dups, 'items' => $created];
    }

    /** Nhập tay 1 atom (luôn là 'proposed'; người có quyền duyệt mới đưa lên 'approved'). */
    public function manual(array $data, ?Website $website, ?User $by): KnowledgeItem
    {
        $title = trim((string) ($data['title'] ?? ''));
        $body = trim((string) ($data['body'] ?? ''));
        if ($title === '' || $body === '') {
            throw new \InvalidArgumentException('Cần có tiêu đề và nội dung.');
        }
        $type = array_key_exists($data['type'] ?? '', KnowledgeItem::TYPES) ? $data['type'] : 'fact';
        $item = KnowledgeItem::create([
            'website_id' => $website?->id, 'type' => $type, 'title' => mb_substr($title, 0, 200), 'body' => mb_substr($body, 0, 5000),
            'entity' => $this->nullable($data['entity'] ?? null, 160), 'field' => $this->nullable($data['field'] ?? null, 120),
            'value' => $this->nullable($data['value'] ?? null, 255), 'value_unit' => $this->nullable($data['value_unit'] ?? null, 40),
            'topic_id' => ($data['topic_id'] ?? '') !== '' ? (int) $data['topic_id'] : null, 'tags' => $this->tags($data['tags'] ?? []),
            'valid_until' => ($data['valid_until'] ?? '') ?: null, 'status' => 'proposed', 'created_by' => $by ? 'user:'.$by->id : 'user',
            'version' => 1, 'content_hash' => KnowledgeDedupe::hash($title, $body),
        ]);
        $item->versions()->create(['version' => 1, 'title' => $item->title, 'body' => $item->body, 'entity' => $item->entity, 'field' => $item->field, 'value' => $item->value, 'value_unit' => $item->value_unit, 'status' => 'proposed', 'changed_by' => $by?->id, 'note' => 'Nhập tay']);

        return $item;
    }

    /** @return list<string> */
    public static function tags(string|array|null $raw): array
    {
        $parts = is_array($raw) ? $raw : explode(',', (string) $raw);
        $tags = array_map(fn ($t) => mb_substr(trim((string) $t), 0, 40), $parts);

        return array_slice(array_values(array_unique(array_filter($tags, fn ($t) => $t !== ''))), 0, 12);
    }

    private function nullable(mixed $v, int $max): ?string
    {
        $v = trim((string) $v);

        return $v === '' ? null : mb_substr($v, 0, $max);
    }

    private function isHeading(string $line, bool $hasBody): bool
    {
        if (preg_match('/^#{1,6}\s+\S/u', $line)) {
            return true;
        }

        return $hasBody && mb_strlen($line) <= 80 && ! preg_match('/[.!?;,。]$/u', $line);
    }

    private function firstSentence(string $body): string
    {
        $one = trim(preg_replace('/\s+/u', ' ', $body) ?? $body);
        $first = preg_split('/(?<=[.!?])\s/u', $one, 2)[0] ?? $one;

        return mb_strlen($first) > 80 ? mb_substr($first, 0, 77).'…' : $first;
    }
}
