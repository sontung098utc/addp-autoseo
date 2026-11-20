<?php

namespace App\Services\Images;

use App\Contracts\ImageGeneratorInterface;
use App\Filament\Support\Schedule\ErrorMasker;
use App\Models\AiProvider;
use App\Services\Images\Http\HttpApiSpec;
use App\Services\Images\Http\HttpTemplate;
use App\Services\Security\UrlSafetyService;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

/**
 * Tạo ảnh qua một API HTTP BẤT KỲ, cấu hình bằng dữ liệu (HttpApiSpec) thay vì viết thêm lớp PHP cho từng dịch vụ.
 * - sync: một lời gọi trả ảnh (base64 hoặc URL). async: gửi job, hỏi trạng thái định kỳ tới khi xong rồi lấy ảnh.
 * - Base URL qua UrlSafetyService; URL ảnh do máy chủ ngoài trả về cũng qua kiểm SSRF, không theo chuyển hướng, giới hạn dung lượng,
 *   nhận dạng ảnh theo byte đầu. Khóa không bao giờ nằm trong thông báo lỗi.
 * - Job async quá hạn chờ: ném lỗi kèm mã job để đối soát; KHÔNG tự gửi lại (tránh tính tiền hai lần).
 * Chưa kiểm với khóa thật; chạy thật phải ghi nhận riêng.
 */
final class CustomHttpImageGenerator implements ImageGeneratorInterface
{
    private const MAX_IMAGE_BYTES = 12 * 1024 * 1024;

    /** @param array<string, mixed> $spec đã qua HttpApiSpec::normalize */
    public function __construct(private string $apiKey, private string $model, private ?string $baseUrl, private array $spec, private float $costVnd) {}

    /** @throws ImageGeneratorUnavailable cấu hình không hợp lệ */
    public static function fromProvider(AiProvider $p, string $model, float $costVnd = 0.0): self
    {
        try {
            $spec = HttpApiSpec::normalize($p->http_config);
        } catch (\InvalidArgumentException $e) {
            throw new ImageGeneratorUnavailable("Tài khoản \"{$p->name}\": cấu hình API tùy chỉnh không hợp lệ ({$e->getMessage()})");
        }

        return new self((string) $p->api_key, $model, $p->base_url, $spec, $costVnd);
    }

    public function estimatedCostVnd(): float
    {
        return $this->costVnd;
    }

    public function generate(string $prompt, array $options = []): array
    {
        $aspect = in_array($options['aspect'] ?? '16:9', ['16:9', '4:3', '1:1', '3:4', '9:16'], true) ? ($options['aspect'] ?? '16:9') : '16:9';
        [$w, $h] = match ($aspect) {
            '1:1' => [1024, 1024], '9:16', '3:4' => [1024, 1536], default => [1536, 1024],
        };
        $vars = ['prompt' => $prompt, 'model' => $this->model, 'aspect' => $aspect, 'size' => "{$w}x{$h}", 'width' => $w, 'height' => $h,
            'resolution' => (string) ($options['resolution'] ?? '1k'), 'request_id' => (string) Str::uuid()];

        $sub = $this->spec['submit'];
        $res = $this->send($sub['method'], $this->path($sub['path'], $vars), HttpTemplate::render($sub['body'], $vars));
        $json = $res->json() ?? [];

        if ($this->spec['mode'] === 'async') {
            $json = $this->waitForJob($json, $vars);
        }
        $src = HttpTemplate::get($json, $this->spec['result']['path']);
        if (is_array($src)) {
            $first = reset($src);
            $src = is_array($first) ? ($first['url'] ?? null) : $first;
        }
        if (! is_string($src) || $src === '') {
            throw new ImageGenerationError('Nhà cung cấp không trả ảnh ở đường dẫn kết quả đã cấu hình (có thể bị bộ lọc chặn hoặc cấu hình result.path sai).');
        }
        [$bytes, $mime] = $this->spec['result']['type'] === 'base64' ? ImageBytes::fromBase64($src) : $this->download($src);

        return ['bytes' => $bytes, 'mime' => $mime, 'model' => $this->model, 'cost_vnd' => $this->costVnd, 'prompt' => $prompt, 'revised_prompt' => null];
    }

    /** Kiểm tra khóa và địa chỉ bằng một lời gọi GET nhẹ, không tạo ảnh (không tốn tiền). @return array{ok: bool, message: string} */
    public function ping(): array
    {
        if (empty($this->apiKey)) {
            return ['ok' => false, 'message' => 'Chưa có khóa API.'];
        }
        $path = $this->spec['health']['path'] ?? null;
        if ($path === null) {
            return ['ok' => false, 'message' => 'Chưa cấu hình đường dẫn kiểm tra kết nối (health.path) nên chưa kiểm tra được mà không tạo ảnh.'];
        }
        try {
            $this->send('GET', $path, null);
        } catch (ImageGenerationError $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        return ['ok' => true, 'message' => 'Kết nối được và khóa được chấp nhận. Chưa tạo ảnh thử.'];
    }

    /** @return list<array{id: string, name: ?string}> model theo cấu hình models; rỗng nếu chưa cấu hình. @throws ImageGenerationError */
    public function listModels(): array
    {
        $m = $this->spec['models'] ?? null;
        if ($m === null) {
            return [];
        }
        $json = $this->send('GET', $m['path'], null)->json() ?? [];
        $list = $m['list_path'] !== '' ? HttpTemplate::get($json, $m['list_path']) : $json;
        if (! is_array($list) || ($m['list_path'] === '' && ! array_is_list($list))) {
            $list = null;
            foreach (['data', 'models', 'items'] as $k) {
                if (is_array($json[$k] ?? null)) {
                    $list = $json[$k];
                    break;
                }
            }
        }
        $out = [];
        foreach ((array) $list as $row) {
            $id = is_array($row) ? HttpTemplate::get($row, $m['id_path']) : $row;
            if (is_scalar($id) && trim((string) $id) !== '') {
                $name = is_array($row) ? HttpTemplate::get($row, $m['name_path']) : null;
                $out[] = ['id' => trim((string) $id), 'name' => is_scalar($name) ? (string) $name : null];
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $submitJson */
    private function waitForJob(array $submitJson, array $vars): array
    {
        $a = $this->spec['async'];
        $jobId = HttpTemplate::get($submitJson, $a['job_id_path']);
        if (! is_scalar($jobId) || (string) $jobId === '') {
            throw new ImageGenerationError('Nhà cung cấp không trả mã job (async.job_id_path sai hoặc job bị từ chối).');
        }
        $jobId = (string) $jobId;
        $waited = 0;
        $transient = 0;
        while ($waited <= $a['timeout_s']) {
            Sleep::for($a['interval_s'])->seconds();
            $waited += $a['interval_s'];
            try {
                $poll = $this->send('GET', $this->path($a['poll_path'], $vars + ['job_id' => $jobId]), null)->json() ?? [];
            } catch (ImageGenerationError $e) {
                // lỗi tạm thời (5xx, 429, mạng) thì hỏi tiếp tới khi hết hạn; 401/403/404 là lỗi thật
                if (! in_array($e->status, [401, 403, 404], true) && ++$transient <= 5) {
                    continue;
                }
                throw new ImageGenerationError($e->getMessage().' (job '.$jobId.' có thể đã được tạo bên nhà cung cấp, cần đối soát, đừng gửi lại)', $e->status);
            }
            $status = strtolower(trim((string) (HttpTemplate::get($poll, $a['status_path']) ?? '')));
            if (in_array($status, $a['done'], true)) {
                return $poll;
            }
            if (in_array($status, $a['failed'], true)) {
                throw new ImageGenerationError("Job {$jobId} thất bại bên nhà cung cấp (trạng thái: {$status}).");
            }
        }

        throw new ImageGenerationError("Quá hạn chờ {$a['timeout_s']} giây: job {$jobId} chưa xong. Job có thể vẫn đang chạy hoặc đã tính tiền, cần đối soát, không tự gửi lại.");
    }

    /** @return array{0: string, 1: string} */
    private function download(string $url): array
    {
        try {
            app(UrlSafetyService::class)->validateUrl($url);
            $res = Http::timeout(60)->connectTimeout(10)->withoutRedirecting()->get($url); // không gửi khóa API tới máy chủ ảnh
        } catch (\InvalidArgumentException $e) {
            throw new ImageGenerationError('URL ảnh không an toàn: '.$this->safe($e->getMessage()));
        } catch (\Throwable $e) {
            throw new ImageGenerationError('Không tải được ảnh kết quả: '.$this->safe($e->getMessage()));
        }
        if (! $res->successful()) {
            throw new ImageGenerationError('Tải ảnh kết quả lỗi HTTP '.$res->status().'.', $res->status());
        }
        $bytes = $res->body();
        if ($bytes === '' || strlen($bytes) > self::MAX_IMAGE_BYTES) {
            throw new ImageGenerationError('Ảnh kết quả rỗng hoặc lớn hơn '.(self::MAX_IMAGE_BYTES / 1048576).' MB.');
        }
        $mime = ImageBytes::mime($bytes);
        if ($mime === null) {
            throw new ImageGenerationError('Dữ liệu tải về không phải ảnh PNG/JPEG/WebP.');
        }

        return [$bytes, $mime];
    }

    /** @param array<string, mixed> $vars */
    private function path(string $tpl, array $vars): string
    {
        return preg_replace_callback('/\{\{(\w+)\}\}/', fn ($m) => rawurlencode((string) ($vars[$m[1]] ?? '')), $tpl);
    }

    private function request(): PendingRequest
    {
        if (empty($this->baseUrl)) {
            throw new ImageGenerationError('Thiếu Base URL cho tài khoản API tùy chỉnh.');
        }
        try {
            app(UrlSafetyService::class)->validateAiBaseUrl($this->baseUrl);
        } catch (\InvalidArgumentException $e) {
            throw new ImageGenerationError('Base URL không an toàn: '.$this->safe($e->getMessage()));
        }
        $req = Http::baseUrl(rtrim($this->baseUrl, '/'))->timeout(120)->connectTimeout(10)->acceptJson()->withoutRedirecting();
        $auth = $this->spec['auth'];

        return match ($auth['type']) {
            'bearer' => $req->withToken($this->apiKey),
            'header' => $req->withHeaders([$auth['name'] => $auth['prefix'].$this->apiKey]),
            default => $req->withQueryParameters([$auth['name'] => $this->apiKey]),
        };
    }

    /** @param array<string, mixed>|null $body */
    private function send(string $method, string $path, ?array $body): Response
    {
        $req = $this->request();
        try {
            $res = $method === 'GET' ? $req->get($path) : $req->send($method, $path, ['json' => $body ?? []]);
        } catch (\Throwable $e) {
            throw new ImageGenerationError('Không gọi được nhà cung cấp ảnh: '.$this->safe($e->getMessage()));
        }
        if ($res->successful()) {
            return $res;
        }
        if (in_array($res->status(), [401, 403], true)) {
            $hint = $this->spec['auth']['type'] === 'bearer' ? 'API này có thể dùng header riêng (ví dụ X-Api-Key) thay vì Bearer.' : 'Kiểm tra khóa và tên header/tham số xác thực.';
            throw new ImageGenerationError('Khóa API bị từ chối (HTTP '.$res->status().'). '.$hint, $res->status());
        }

        throw ImageGenerationError::http($res);
    }

    private function safe(string $m): string
    {
        return mb_substr(ErrorMasker::mask($this->apiKey !== '' ? str_replace($this->apiKey, '***', $m) : $m), 0, 200);
    }
}
