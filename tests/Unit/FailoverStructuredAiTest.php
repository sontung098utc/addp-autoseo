<?php

namespace Tests\Unit;

use App\Contracts\StructuredAiInterface;
use App\Services\Ai\AiQuotaExceeded;
use App\Services\Ai\FailoverStructuredAi;
use PHPUnit\Framework\TestCase;

class FailoverStructuredAiTest extends TestCase
{
    private function ai(string $name, ?\Throwable $fail = null): StructuredAiInterface
    {
        return new class($name, $fail) implements StructuredAiInterface
        {
            public int $calls = 0;

            public function __construct(public string $name, private ?\Throwable $fail) {}

            public function generateStructured(string $system, string $user): array
            {
                $this->calls++;
                if ($this->fail) {
                    throw $this->fail;
                }

                return ['data' => ['by' => $this->name], 'tokens_used' => 1, 'cost_vnd' => 0.0, 'model' => $this->name];
            }
        };
    }

    public function test_dung_model_nhe_truoc_va_khong_goi_model_manh_khi_khong_can(): void
    {
        $light = $this->ai('agy');
        $strong = $this->ai('sonnet');
        $r = (new FailoverStructuredAi([$light, $strong]))->generateStructured('s', 'u');
        $this->assertSame('agy', $r['data']['by']);
        $this->assertSame(0, $strong->calls, 'Việc nhẹ không được ăn hạn mức của model mạnh.');
    }

    public function test_model_nhe_het_han_muc_thi_chuyen_sang_model_ke_tiep_khong_dung_phien(): void
    {
        $light = $this->ai('agy', new AiQuotaExceeded('hết hạn mức'));
        $strong = $this->ai('sonnet');
        $r = (new FailoverStructuredAi([$light, $strong]))->generateStructured('s', 'u');
        $this->assertSame('sonnet', $r['data']['by']);
    }

    public function test_tat_ca_loi_thi_nem_loi_cuoi_va_bo_trung_lap(): void
    {
        $a = $this->ai('a', new \RuntimeException('lỗi a'));
        $f = new FailoverStructuredAi([$a, $a, $this->ai('b', new \RuntimeException('lỗi b'))]);
        try {
            $f->generateStructured('s', 'u');
            $this->fail('phải ném lỗi');
        } catch (\RuntimeException $e) {
            $this->assertSame('lỗi b', $e->getMessage());
        }
        $this->assertSame(1, $a->calls, 'AI trùng lặp chỉ được gọi một lần.');
        $this->assertTrue((new FailoverStructuredAi([]))->isEmpty());
    }
}
