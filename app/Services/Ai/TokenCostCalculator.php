<?php

namespace App\Services\Ai;

class TokenCostCalculator
{
    /**
     * Bảng giá chính thức của các mô hình LLM (Đơn vị: USD trên 1 triệu Tokens)
     */
    public const MODEL_PRICING = [
        // Giá Gemini API (gói trả phí Standard, văn bản). Nguồn: https://aistudio.google.com/docs/pricing (ai.google.dev/gemini-api/docs/pricing chuyển hướng về đây), lấy ngày 12/11/2026.
        // Đầu ra đã gồm token suy luận (thinking). LƯU Ý: gemini-3.6-flash và gemini-3.8-flash TĂNG GẤP ĐÔI từ 01/01/2027 (vào 1,50 / ra 7,50); cập nhật bảng này khi đó.
        'gemini-3.5-flash-lite' => [
            'name' => 'Google Gemini 3.5 Flash-Lite',
            'input_per_million' => 0.30,
            'output_per_million' => 2.50,
        ],
        'gemini-3.6-flash' => [
            'name' => 'Google Gemini 3.6 Flash',
            'input_per_million' => 0.75,
            'output_per_million' => 3.75,
        ],
        'gemini-3.8-flash' => [
            'name' => 'Google Gemini 3.8 Flash',
            'input_per_million' => 0.75,
            'output_per_million' => 3.75,
        ],
        'gemini-3.1-pro-preview' => [
            'name' => 'Google Gemini 3.1 Pro (preview, prompt ≤200k token)',
            'input_per_million' => 2.00,
            'output_per_million' => 12.00,
        ],
        'gemini-1.5-flash' => [
            'name' => 'Google Gemini 1.5 Flash',
            'input_per_million' => 0.075,
            'output_per_million' => 0.30,
        ],
        'gemini-1.5-pro' => [
            'name' => 'Google Gemini 1.5 Pro',
            'input_per_million' => 1.25,
            'output_per_million' => 5.00,
        ],
        'gpt-4o-mini' => [
            'name' => 'OpenAI GPT-4o Mini',
            'input_per_million' => 0.15,
            'output_per_million' => 0.60,
        ],
        'gpt-4o' => [
            'name' => 'OpenAI GPT-4o (Full)',
            'input_per_million' => 2.50,
            'output_per_million' => 10.00,
        ],
    ];

    /**
     * Tỉ giá USD / VND mặc định
     */
    protected float $exchangeRate;

    public function __construct(float $exchangeRate = 25500.0)
    {
        $this->exchangeRate = $exchangeRate;
    }

    /**
     * Tính toán chi phí thực tế cho một lượt sinh bài viết
     *
     * @param int $promptTokens Số token đầu vào
     * @param int $completionTokens Số token đầu ra
     * @param string $model Tên model
     * @param array|null $rates Giá riêng [input_per_million, output_per_million] (USD); null thì tra bảng MODEL_PRICING
     * @return array [ 'usd' => float, 'vnd' => float, 'details' => array ]
     */
    public function calculate(int $promptTokens, int $completionTokens, string $model = 'gemini-1.5-flash', ?array $rates = null): array
    {
        $rates ??= self::MODEL_PRICING[$model] ?? self::MODEL_PRICING['gemini-1.5-flash'];
        $rates += ['name' => $model];

        $inputCostUsd = ($promptTokens / 1_000_000) * $rates['input_per_million'];
        $outputCostUsd = ($completionTokens / 1_000_000) * $rates['output_per_million'];
        $totalCostUsd = $inputCostUsd + $outputCostUsd;
        $totalCostVnd = $totalCostUsd * $this->exchangeRate;

        return [
            'model' => $model,
            'model_name' => $rates['name'],
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'total_tokens' => $promptTokens + $completionTokens,
            'input_cost_usd' => round($inputCostUsd, 6),
            'output_cost_usd' => round($outputCostUsd, 6),
            'total_cost_usd' => round($totalCostUsd, 6),
            'total_cost_vnd' => round($totalCostVnd, 2),
            'cost_100_articles_vnd' => round($totalCostVnd * 100, 0),
        ];
    }
}
