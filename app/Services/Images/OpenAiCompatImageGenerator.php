<?php

namespace App\Services\Images;

use App\Contracts\ImageGeneratorInterface;
use App\Filament\Support\Schedule\ErrorMasker;
use App\Services\Security\UrlSafetyService;
use Illuminate\Support\Facades\Http;

/** POST {base_url}/images/generations. Chỉ nhận ảnh dạng b64_json (không tải URL do máy chủ khác trả về, tránh SSRF). base_url qua kiểm an toàn. */
final class OpenAiCompatImageGenerator implements ImageGeneratorInterface
{
    public function __construct(private string $apiKey, private string $model, private ?string $baseUrl, private float $costVnd) {}

    public function estimatedCostVnd(): float
    {
        return $this->costVnd;
    }

    public function generate(string $prompt, array $options = []): array
    {
        if (empty($this->baseUrl)) {
            throw new ImageGenerationError('Thiếu base_url cho nhà cung cấp OpenAI-tương thích.');
        }
        try {
            app(UrlSafetyService::class)->validateAiBaseUrl($this->baseUrl);
        } catch (\InvalidArgumentException $e) {
            throw new ImageGenerationError('base_url không an toàn: '.ErrorMasker::mask($e->getMessage()));
        }
        try {
            $res = Http::baseUrl(rtrim($this->baseUrl, '/'))->timeout(120)->connectTimeout(10)->withToken($this->apiKey)->acceptJson()
                ->post('/images/generations', ['model' => $this->model, 'prompt' => $prompt, 'n' => 1, 'size' => '1024x1024']);
        } catch (\Throwable $e) {
            throw new ImageGenerationError('Không gọi được nhà cung cấp ảnh: '.ErrorMasker::mask($e->getMessage()));
        }
        if (! $res->successful()) {
            throw ImageGenerationError::http($res);
        }
        $item = ($res->json() ?? [])['data'][0] ?? [];
        [$bytes, $mime] = ImageBytes::fromBase64($item['b64_json'] ?? null);

        return ['bytes' => $bytes, 'mime' => $mime, 'model' => $this->model, 'cost_vnd' => $this->costVnd,
            'prompt' => $prompt, 'revised_prompt' => isset($item['revised_prompt']) && is_string($item['revised_prompt']) ? $item['revised_prompt'] : null];
    }
}
