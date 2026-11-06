<?php

namespace App\Services\Ai;

class TokenCostCalculator
{
    /**
     * Bảng giá chính thức của các mô hình LLM (Đơn vị: USD trên 1 triệu Tokens)
     */
    public const MODEL_PRICING = [
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
     * @return array [ 'usd' => float, 'vnd' => float, 'details' => array ]
     */
    public function calculate(int $promptTokens, int $completionTokens, string $model = 'gemini-1.5-flash'): array
    {
        $rates = self::MODEL_PRICING[$model] ?? self::MODEL_PRICING['gemini-1.5-flash'];

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
