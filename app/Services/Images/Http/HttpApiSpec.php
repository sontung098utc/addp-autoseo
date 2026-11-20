<?php

namespace App\Services\Images\Http;

use InvalidArgumentException;

/**
 * Cấu hình của một API tạo ảnh tùy chỉnh (cột ai_providers.http_config). normalize() kiểm tra và chuẩn hóa; sai thì ném
 * InvalidArgumentException với thông báo tiếng Việt. Không chứa khóa API.
 *
 * Mẫu có sẵn (presets) viết theo tài liệu công khai của từng dịch vụ; CHƯA chạy thật với khóa thật cho tới khi có ghi nhận kiểm thử.
 */
final class HttpApiSpec
{
    private const PATH = '~^/(?!/)[A-Za-z0-9_\-./{}?=&%,:@]*$~';

    /** @return array<string, mixed> mẫu cấu hình theo khóa: openai_images | sangtao */
    public static function preset(string $key): array
    {
        return match ($key) {
            'sangtao' => [
                'preset' => 'sangtao',
                'auth' => ['type' => 'header', 'name' => 'X-Api-Key', 'prefix' => ''],
                'mode' => 'async',
                'submit' => ['method' => 'POST', 'path' => '/images/generations',
                    'body' => ['model' => '{{model}}', 'prompt' => '{{prompt}}', 'aspectRatio' => '{{aspect}}', 'resolution' => '1k', 'format' => 'png', 'idempotencyKey' => '{{request_id}}']],
                'async' => ['job_id_path' => 'jobId|data.jobId|id', 'poll_path' => '/images/generations/{{job_id}}', 'status_path' => 'status|data.status',
                    'done' => ['complete'], 'failed' => ['error'], 'interval_s' => 4, 'timeout_s' => 180],
                'result' => ['type' => 'url', 'path' => 'resultUrl|resultImages.0|data.resultUrl'],
                'health' => ['method' => 'GET', 'path' => '/models'],
                'models' => ['path' => '/models?category=image', 'list_path' => '', 'id_path' => 'slug|id', 'name_path' => 'displayName|name'],
            ],
            default => [
                'preset' => 'openai_images',
                'auth' => ['type' => 'bearer', 'name' => '', 'prefix' => ''],
                'mode' => 'sync',
                'submit' => ['method' => 'POST', 'path' => '/images/generations', 'body' => ['model' => '{{model}}', 'prompt' => '{{prompt}}', 'n' => 1, 'size' => '{{size}}']],
                'result' => ['type' => 'base64', 'path' => 'data.0.b64_json'],
                'health' => ['method' => 'GET', 'path' => '/models'],
                'models' => ['path' => '/models', 'list_path' => 'data', 'id_path' => 'id', 'name_path' => 'name'],
            ],
        };
    }

    /**
     * @param  array<string, mixed>|string|null  $raw  mảng hoặc chuỗi JSON
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException
     */
    public static function normalize(array|string|null $raw): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (! is_array($raw)) {
            throw new InvalidArgumentException('Cấu hình API phải là JSON hợp lệ.');
        }

        $auth = (array) ($raw['auth'] ?? []);
        $type = (string) ($auth['type'] ?? 'bearer');
        if (! in_array($type, ['header', 'bearer', 'query'], true)) {
            throw new InvalidArgumentException('auth.type phải là header, bearer hoặc query.');
        }
        $name = trim((string) ($auth['name'] ?? ''));
        if ($type !== 'bearer' && ! preg_match('/^[A-Za-z0-9_\-]{1,60}$/', $name)) {
            throw new InvalidArgumentException('auth.name (tên header hoặc tham số) chỉ gồm chữ, số, gạch, tối đa 60 ký tự.');
        }
        if ($type === 'header' && in_array(strtolower($name), ['host', 'cookie', 'content-length', 'transfer-encoding'], true)) {
            throw new InvalidArgumentException('auth.name không được là header hệ thống.');
        }
        $prefix = (string) ($auth['prefix'] ?? '');
        if (mb_strlen($prefix) > 20 || preg_match('/[\r\n]/', $prefix)) {
            throw new InvalidArgumentException('auth.prefix tối đa 20 ký tự, không xuống dòng.');
        }

        $mode = (string) ($raw['mode'] ?? 'sync');
        if (! in_array($mode, ['sync', 'async'], true)) {
            throw new InvalidArgumentException('mode phải là sync hoặc async.');
        }

        $submit = (array) ($raw['submit'] ?? []);
        $method = strtoupper((string) ($submit['method'] ?? 'POST'));
        if (! in_array($method, ['POST', 'PUT'], true)) {
            throw new InvalidArgumentException('submit.method phải là POST hoặc PUT.');
        }
        $body = $submit['body'] ?? [];
        if (! is_array($body)) {
            throw new InvalidArgumentException('submit.body phải là đối tượng JSON.');
        }
        $spec = [
            'preset' => isset($raw['preset']) ? (string) $raw['preset'] : null,
            'auth' => ['type' => $type, 'name' => $name, 'prefix' => $prefix],
            'mode' => $mode,
            'submit' => ['method' => $method, 'path' => self::path($submit['path'] ?? null, 'submit.path', ['model']), 'body' => $body],
        ];
        $unknown = array_diff(HttpTemplate::placeholdersIn($body), array_diff(HttpTemplate::PLACEHOLDERS, ['job_id']));
        if ($unknown !== []) {
            throw new InvalidArgumentException('submit.body có chỗ trống không hỗ trợ: {{'.implode('}}, {{', $unknown).'}}.');
        }

        $result = (array) ($raw['result'] ?? []);
        $rtype = (string) ($result['type'] ?? '');
        $rpath = trim((string) ($result['path'] ?? ''));
        if (! in_array($rtype, ['url', 'base64'], true) || $rpath === '') {
            throw new InvalidArgumentException('result cần type (url hoặc base64) và path tới ảnh trong phản hồi.');
        }
        $spec['result'] = ['type' => $rtype, 'path' => $rpath];

        if ($mode === 'async') {
            $a = (array) ($raw['async'] ?? []);
            $poll = self::path($a['poll_path'] ?? null, 'async.poll_path', ['model', 'job_id']);
            if (! str_contains($poll, '{{job_id}}')) {
                throw new InvalidArgumentException('async.poll_path phải chứa {{job_id}}.');
            }
            $done = array_values(array_filter(array_map(fn ($v) => strtolower(trim((string) $v)), (array) ($a['done'] ?? []))));
            $failed = array_values(array_filter(array_map(fn ($v) => strtolower(trim((string) $v)), (array) ($a['failed'] ?? []))));
            foreach (['job_id_path', 'status_path'] as $k) {
                if (trim((string) ($a[$k] ?? '')) === '') {
                    throw new InvalidArgumentException("async.{$k} không được để trống.");
                }
            }
            if ($done === []) {
                throw new InvalidArgumentException('async.done cần ít nhất một giá trị trạng thái "xong".');
            }
            $spec['async'] = ['job_id_path' => trim((string) $a['job_id_path']), 'poll_path' => $poll, 'status_path' => trim((string) $a['status_path']),
                'done' => $done, 'failed' => $failed, 'interval_s' => max(1, min(30, (int) ($a['interval_s'] ?? 4))), 'timeout_s' => max(10, min(600, (int) ($a['timeout_s'] ?? 180)))];
        }

        if (! empty($raw['health']['path'])) {
            $spec['health'] = ['method' => 'GET', 'path' => self::path($raw['health']['path'], 'health.path', [])];
        }
        if (! empty($raw['models']['path'])) {
            $m = (array) $raw['models'];
            $spec['models'] = ['path' => self::path($m['path'], 'models.path', []), 'list_path' => trim((string) ($m['list_path'] ?? '')),
                'id_path' => trim((string) ($m['id_path'] ?? '')) ?: 'id', 'name_path' => trim((string) ($m['name_path'] ?? '')) ?: 'name'];
        }

        return $spec;
    }

    /** @param list<string> $allowed chỗ trống được phép trong đường dẫn */
    private static function path(mixed $p, string $field, array $allowed): string
    {
        $p = trim((string) $p);
        if (! preg_match(self::PATH, $p) || str_contains($p, '..')) {
            throw new InvalidArgumentException("{$field} phải bắt đầu bằng / và chỉ là đường dẫn (không có tên miền, không có ..).");
        }
        $bad = array_diff(HttpTemplate::placeholdersIn($p), $allowed);
        if ($bad !== []) {
            throw new InvalidArgumentException("{$field} có chỗ trống không hỗ trợ: {{".implode('}}, {{', $bad).'}}.');
        }

        return $p;
    }
}
