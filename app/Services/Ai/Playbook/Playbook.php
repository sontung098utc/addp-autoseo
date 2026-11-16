<?php

namespace App\Services\Ai\Playbook;

/** Một playbook đã được kiểm hợp lệ. Bất biến: sửa nội dung = phiên bản mới (đổi version/tên file). */
final class Playbook
{
    /**
     * @param  list<string>  $rubricRefs
     * @param  array{source: string, source_version: string, license: string, adaptations: string}  $lineage
     */
    public function __construct(
        public readonly string $key,
        public readonly int $version,
        public readonly string $role,
        public readonly string $title,
        public readonly string $status,
        public readonly string $modelTier,
        public readonly array $rubricRefs,
        public readonly array $lineage,
        public readonly string $reviewedAt,
        public readonly string $body,
        public readonly string $checksum,
        public readonly string $path,
    ) {
    }

    /** "<role>/<key>@<version>" — chuỗi ghi vào mỗi lần chạy để truy vết đúng phiên bản đã dùng. */
    public function ref(): string
    {
        return "{$this->role}/{$this->key}@{$this->version}";
    }

    /** Chỉ playbook "active" mới được pipeline tự động dùng (draft/retired thì không). */
    public function isRunnable(): bool
    {
        return $this->status === 'active';
    }
}
