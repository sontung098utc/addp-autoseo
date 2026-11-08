<?php

namespace App\Services\Knowledge;

use App\Models\KnowledgeItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * AG-S20: duyệt/sửa/loại/gộp atom. Quyền duyệt (tạm): admin + approver ("quyền duyệt tính sau theo yêu cầu").
 * Chặn duyệt khi còn mâu thuẫn chưa giải quyết — phải dùng resolveConflict() để chọn bản đúng.
 */
final class KnowledgeReview
{
    public function __construct(private KnowledgeConflicts $conflicts = new KnowledgeConflicts())
    {
    }

    public static function canManage(?User $u): bool
    {
        return $u !== null && $u->is_active && $u->isApprover();
    }

    private function authorize(?User $by): void
    {
        if (! self::canManage($by)) {
            throw new KnowledgeError('Bạn chưa có quyền duyệt kiến thức.');
        }
    }

    public function approve(KnowledgeItem $item, ?User $by): KnowledgeItem
    {
        $this->authorize($by);
        if ($item->status === 'approved') {
            return $item;
        }
        if ($this->conflicts->has($item)) {
            throw new KnowledgeError('Mục này đang mâu thuẫn với mục khác (cùng thực thể, cùng trường, khác giá trị). Hãy chọn bản đúng ở tab Cảnh báo trước khi duyệt.');
        }

        return $this->setStatus($item, 'approved', $by, 'Duyệt');
    }

    /** Duyệt hàng loạt theo nguồn: bỏ qua (không duyệt) mục còn mâu thuẫn. @return array{approved:int,blocked:int} */
    public function approveSource(int $sourceId, ?User $by): array
    {
        $this->authorize($by);
        $ok = $blocked = 0;
        foreach (KnowledgeItem::query()->where('source_id', $sourceId)->where('status', 'proposed')->get() as $item) {
            try {
                $this->approve($item, $by);
                $ok++;
            } catch (KnowledgeError) {
                $blocked++;
            }
        }

        return ['approved' => $ok, 'blocked' => $blocked];
    }

    public function reject(KnowledgeItem $item, ?User $by): KnowledgeItem
    {
        $this->authorize($by);

        return $this->setStatus($item, 'rejected', $by, 'Loại');
    }

    public function markOutdated(KnowledgeItem $item, ?User $by): KnowledgeItem
    {
        $this->authorize($by);

        return $this->setStatus($item, 'outdated', $by, 'Đánh dấu cũ');
    }

    /** Chọn bản đúng: duyệt $winner, các mục mâu thuẫn bị loại (đề xuất) hoặc đánh dấu cũ (đã duyệt). */
    public function resolveConflict(KnowledgeItem $winner, ?User $by): KnowledgeItem
    {
        $this->authorize($by);

        return DB::transaction(function () use ($winner, $by) {
            foreach ($this->conflicts->for($winner) as $other) {
                $this->setStatus($other, $other->status === 'approved' ? 'outdated' : 'rejected', $by, 'Thua trong giải quyết mâu thuẫn với #'.$winner->id);
            }

            return $this->setStatus($winner, 'approved', $by, 'Chọn là bản đúng');
        });
    }

    /**
     * Sửa mục. Đổi nội dung (tiêu đề/nội dung/thực thể/trường/giá trị/đơn vị) → phiên bản mới và, nếu đang 'approved', về 'proposed' (phải duyệt lại).
     * Chỉ đổi chủ đề/thẻ/loại/hạn → không tạo phiên bản mới.
     *
     * @param  array<string,mixed>  $data
     */
    public function update(KnowledgeItem $item, array $data, ?User $by): KnowledgeItem
    {
        $this->authorize($by);
        $title = trim((string) ($data['title'] ?? $item->title));
        $body = trim((string) ($data['body'] ?? $item->body));
        if ($title === '' || $body === '') {
            throw new KnowledgeError('Tiêu đề và nội dung không được để trống.');
        }
        $n = fn ($k, $max) => array_key_exists($k, $data) ? (trim((string) $data[$k]) === '' ? null : mb_substr(trim((string) $data[$k]), 0, $max)) : $item->{$k};
        $content = ['title' => mb_substr($title, 0, 200), 'body' => mb_substr($body, 0, 5000), 'entity' => $n('entity', 160), 'field' => $n('field', 120), 'value' => $n('value', 255), 'value_unit' => $n('value_unit', 40)];
        $meta = [
            'type' => array_key_exists($data['type'] ?? $item->type, KnowledgeItem::TYPES) ? ($data['type'] ?? $item->type) : $item->type,
            'topic_id' => array_key_exists('topic_id', $data) ? (($data['topic_id'] ?? '') !== '' ? (int) $data['topic_id'] : null) : $item->topic_id,
            'tags' => array_key_exists('tags', $data) ? KnowledgeIngest::tags($data['tags']) : $item->tags,
            'valid_until' => array_key_exists('valid_until', $data) ? ($data['valid_until'] ?: null) : $item->valid_until,
        ];
        $changed = false;
        foreach ($content as $k => $v) {
            $changed = $changed || (string) $item->{$k} !== (string) $v;
        }

        return DB::transaction(function () use ($item, $content, $meta, $changed, $by) {
            if (! $changed) {
                $item->update($meta);

                return $item;
            }
            if (! $item->versions()->where('version', $item->version)->exists()) { // bảo đảm bản đang đổi được giữ lại
                $this->snapshot($item, $by, 'Bản gốc');
            }
            $wasApproved = $item->status === 'approved';
            $item->update($content + $meta + [
                'version' => $item->version + 1, 'content_hash' => KnowledgeDedupe::hash($content['title'], $content['body']),
                'status' => $wasApproved ? 'proposed' : $item->status, 'approved_by' => $wasApproved ? null : $item->approved_by, 'approved_at' => $wasApproved ? null : $item->approved_at,
            ]);
            $this->snapshot($item, $by, $wasApproved ? 'Sửa nội dung (về Đề xuất, cần duyệt lại)' : 'Sửa nội dung');

            return $item;
        });
    }

    /** Gộp $dup vào $keep: $dup bị loại, ghi chú trong lịch sử của $keep. */
    public function merge(KnowledgeItem $keep, KnowledgeItem $dup, ?User $by): KnowledgeItem
    {
        $this->authorize($by);
        if ($keep->id === $dup->id) {
            throw new KnowledgeError('Không thể gộp một mục với chính nó.');
        }
        DB::transaction(function () use ($keep, $dup, $by) {
            $this->setStatus($dup, 'rejected', $by, 'Gộp vào #'.$keep->id);
            $keep->update(['tags' => array_values(array_unique(array_merge($keep->tags ?? [], $dup->tags ?? [])))]);
        });

        return $keep->refresh();
    }

    private function setStatus(KnowledgeItem $item, string $status, ?User $by, string $note): KnowledgeItem
    {
        $attrs = ['status' => $status];
        if ($status === 'approved') {
            $attrs += ['approved_by' => $by?->id, 'approved_at' => now()];
        }
        $item->update($attrs);
        $this->snapshot($item, $by, $note);

        return $item;
    }

    /** Ghi dòng lịch sử cho phiên bản hiện tại (cập nhật nếu đã có để mỗi phiên bản một dòng, ghi chú nối thêm). */
    private function snapshot(KnowledgeItem $item, ?User $by, string $note): void
    {
        $row = $item->versions()->where('version', $item->version)->first();
        $data = ['title' => $item->title, 'body' => $item->body, 'entity' => $item->entity, 'field' => $item->field, 'value' => $item->value, 'value_unit' => $item->value_unit, 'status' => $item->status, 'changed_by' => $by?->id];
        if ($row) {
            $row->update($data + ['note' => mb_substr(trim(($row->note ? $row->note.' → ' : '').$note), 0, 200)]);
        } else {
            $item->versions()->create($data + ['version' => $item->version, 'note' => mb_substr($note, 0, 200)]);
        }
    }
}
