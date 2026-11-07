<?php

namespace App\Services\Ai\Playbook;

/**
 * AG-K02: nạp playbook từ resources/playbooks/<role>/<key>.v<N>.md (nguồn sự thật nằm trong repo, review bằng PR).
 *
 * Định dạng: phần đầu (frontmatter) giữa hai dòng '---' gồm khóa: giá trị; danh sách bằng '  - mục'; ánh xạ một cấp bằng '  khóa: giá trị';
 * phần sau là nội dung (các bước, mẫu prompt...). KHÔNG dùng YAML đầy đủ (không có thư viện YAML trong phụ thuộc chính thức).
 *
 * Quy tắc từ chối (InvalidPlaybook): thiếu khóa bắt buộc; THIẾU xuất xứ (lineage: source, source_version, license, adaptations);
 * tên file không khớp key/version/thư mục vai trò; version không phải số nguyên dương; status/model_tier ngoài danh sách; ngày rà soát sai.
 */
final class PlaybookLoader
{
    public const STATUSES = ['draft', 'active', 'retired'];

    public const MODEL_TIERS = ['reasoning', 'standard', 'cheap'];

    private const REQUIRED = ['key', 'version', 'role', 'title', 'status', 'model_tier', 'lineage', 'reviewed_at'];

    private const LINEAGE_REQUIRED = ['source', 'source_version', 'license', 'adaptations'];

    public function __construct(private readonly ?string $baseDir = null)
    {
    }

    public function baseDir(): string
    {
        return $this->baseDir ?? resource_path('playbooks');
    }

    /** @return list<Playbook> sắp theo vai trò, key, version. Một file hỏng làm cả lần nạp thất bại (không bỏ sót âm thầm). */
    public function all(): array
    {
        $out = [];
        foreach (glob($this->baseDir().'/*/*.md') ?: [] as $file) {
            $out[] = $this->load($file);
        }
        usort($out, fn (Playbook $a, Playbook $b) => [$a->role, $a->key, $a->version] <=> [$b->role, $b->key, $b->version]);

        return $out;
    }

    public function find(string $role, string $key, ?int $version = null): ?Playbook
    {
        $hits = array_values(array_filter($this->all(), fn (Playbook $p) => $p->role === $role && $p->key === $key && ($version === null || $p->version === $version)));

        return $hits === [] ? null : end($hits);
    }

    /** Phiên bản "active" cao nhất; null nếu chưa có (draft không được dùng cho chạy tự động). */
    public function latestActive(string $role, string $key): ?Playbook
    {
        $active = array_values(array_filter($this->all(), fn (Playbook $p) => $p->role === $role && $p->key === $key && $p->isRunnable()));

        return $active === [] ? null : end($active);
    }

    public function load(string $path): Playbook
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new InvalidPlaybook("Không đọc được playbook: {$path}");
        }
        $raw = str_replace("\r\n", "\n", $raw);
        if (! preg_match('/\A---\n(.*?)\n---\n?(.*)\z/s', $raw, $m)) {
            throw new InvalidPlaybook(basename($path).': thiếu phần đầu (frontmatter) giữa hai dòng ---');
        }
        $meta = $this->parse($m[1], basename($path));
        $body = trim($m[2]);

        foreach (self::REQUIRED as $k) {
            if (! array_key_exists($k, $meta) || $meta[$k] === '' || $meta[$k] === []) {
                throw new InvalidPlaybook(basename($path).": thiếu khóa bắt buộc '{$k}'");
            }
        }
        $lineage = $meta['lineage'];
        if (! is_array($lineage)) {
            throw new InvalidPlaybook(basename($path).": 'lineage' phải là ánh xạ (source, source_version, license, adaptations)");
        }
        foreach (self::LINEAGE_REQUIRED as $k) {
            if (trim((string) ($lineage[$k] ?? '')) === '') {
                throw new InvalidPlaybook(basename($path).": thiếu xuất xứ lineage.{$k} (mọi playbook phải ghi nguồn học và chỗ đã chỉnh)");
            }
        }
        if (! ctype_digit((string) $meta['version']) || (int) $meta['version'] < 1) {
            throw new InvalidPlaybook(basename($path).': version phải là số nguyên dương');
        }
        if (! in_array($meta['status'], self::STATUSES, true)) {
            throw new InvalidPlaybook(basename($path).': status phải là '.implode('|', self::STATUSES));
        }
        if (! in_array($meta['model_tier'], self::MODEL_TIERS, true)) {
            throw new InvalidPlaybook(basename($path).': model_tier phải là '.implode('|', self::MODEL_TIERS));
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $meta['reviewed_at']) || strtotime((string) $meta['reviewed_at']) === false) {
            throw new InvalidPlaybook(basename($path).': reviewed_at phải là ngày YYYY-MM-DD');
        }
        if ($body === '') {
            throw new InvalidPlaybook(basename($path).': nội dung playbook rỗng');
        }

        $expected = $meta['key'].'.v'.$meta['version'].'.md';
        if (basename($path) !== $expected || basename(dirname($path)) !== $meta['role']) {
            throw new InvalidPlaybook(basename($path).": tên file/thư mục phải là {$meta['role']}/{$expected}");
        }

        return new Playbook(
            (string) $meta['key'], (int) $meta['version'], (string) $meta['role'], (string) $meta['title'], (string) $meta['status'],
            (string) $meta['model_tier'], array_values(array_map('strval', (array) ($meta['rubric_refs'] ?? []))),
            ['source' => (string) $lineage['source'], 'source_version' => (string) $lineage['source_version'], 'license' => (string) $lineage['license'], 'adaptations' => (string) $lineage['adaptations']],
            (string) $meta['reviewed_at'], $body, hash('sha256', $raw), $path,
        );
    }

    /** @return array<string, mixed> */
    private function parse(string $text, string $file): array
    {
        $meta = [];
        $current = null;
        foreach (explode("\n", $text) as $line) {
            if (trim($line) === '' || str_starts_with(ltrim($line), '#')) {
                continue;
            }
            if (preg_match('/^([a-z_]+):\s*(.*)$/', $line, $m)) {
                $current = $m[1];
                $meta[$current] = $m[2] === '' ? [] : $this->scalar($m[2]);
            } elseif ($current !== null && preg_match('/^\s+-\s+(.*)$/', $line, $m)) {
                if (! is_array($meta[$current])) {
                    throw new InvalidPlaybook("{$file}: '{$current}' vừa là giá trị vừa là danh sách");
                }
                $meta[$current][] = $this->scalar($m[1]);
            } elseif ($current !== null && preg_match('/^\s+([a-z_]+):\s*(.*)$/', $line, $m)) {
                if (! is_array($meta[$current])) {
                    throw new InvalidPlaybook("{$file}: '{$current}' vừa là giá trị vừa là ánh xạ");
                }
                $meta[$current][$m[1]] = $this->scalar($m[2]);
            } else {
                throw new InvalidPlaybook("{$file}: dòng không hiểu được: ".trim($line));
            }
        }

        return $meta;
    }

    private function scalar(string $v): string
    {
        $v = trim($v);
        if (strlen($v) >= 2 && (($v[0] === '"' && substr($v, -1) === '"') || ($v[0] === "'" && substr($v, -1) === "'"))) {
            $v = substr($v, 1, -1);
        }

        return $v;
    }
}
