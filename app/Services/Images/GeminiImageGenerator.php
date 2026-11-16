<?php

namespace App\Services\Images;

use App\Contracts\ImageGeneratorInterface;
use App\Filament\Support\Schedule\ErrorMasker;
use Illuminate\Support\Facades\Http;

/**
 * Google: model 'imagen*' dùng :predict; model khác (dòng Gemini ảnh) dùng :generateContent. Khóa gửi qua header, không nằm trong URL.
 * Chưa gọi API thật trong dự án này: định dạng theo tài liệu công khai, cần thử bằng khóa thật trước khi tin.
 */
final class GeminiImageGenerator implements ImageGeneratorInterface
{
    private const BASE = 'https://generativelanguage.googleapis.com/v1beta/models/';

    public function __construct(private string $apiKey, private string $model, private float $costVnd) {}

    public function estimatedCostVnd(): float
    {
        return $this->costVnd;
    }

    public function generate(string $prompt, array $options = []): array
    {
        $aspect = in_array($options['aspect'] ?? '16:9', ['16:9', '4:3', '1:1', '3:4', '9:16'], true) ? ($options['aspect'] ?? '16:9') : '16:9';
        $imagen = str_starts_with($this->model, 'imagen');
        try {
            $req = Http::timeout(90)->connectTimeout(10)->withHeaders(['x-goog-api-key' => $this->apiKey])->acceptJson();
            $res = $imagen
                ? $req->post(self::BASE.$this->model.':predict', ['instances' => [['prompt' => $prompt]], 'parameters' => ['sampleCount' => 1, 'aspectRatio' => $aspect]])
                : $req->post(self::BASE.$this->model.':generateContent', ['contents' => [['parts' => [['text' => $prompt]]]], 'generationConfig' => ['responseModalities' => ['IMAGE']]]);
        } catch (\Throwable $e) {
            throw new ImageGenerationError('Không gọi được nhà cung cấp ảnh: '.ErrorMasker::mask($e->getMessage()));
        }
        if (! $res->successful()) {
            throw ImageGenerationError::http($res);
        }
        $body = $res->json() ?? [];
        $b64 = null;
        if ($imagen) {
            $b64 = $body['predictions'][0]['bytesBase64Encoded'] ?? null;
        } else {
            foreach ($body['candidates'][0]['content']['parts'] ?? [] as $p) {
                if (isset($p['inlineData']['data'])) {
                    $b64 = $p['inlineData']['data'];
                    break;
                }
            }
        }
        if ($b64 === null) {
            throw new ImageGenerationError('Nhà cung cấp không trả ảnh (có thể bị bộ lọc an toàn chặn).');
        }
        [$bytes, $mime] = ImageBytes::fromBase64($b64);

        return ['bytes' => $bytes, 'mime' => $mime, 'model' => $this->model, 'cost_vnd' => $this->costVnd, 'prompt' => $prompt, 'revised_prompt' => null];
    }
}
