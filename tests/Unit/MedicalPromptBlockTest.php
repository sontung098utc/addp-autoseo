<?php

namespace Tests\Unit;

use App\Models\Article;
use App\Services\Ai\OpenAiCompatibleProvider;
use Tests\TestCase;

class MedicalPromptBlockTest extends TestCase
{
    public function test_medical_block_only_when_flagged(): void
    {
        $m = new \ReflectionMethod(\App\Services\Ai\AbstractLlmProvider::class, 'medicalBlock');
        $m->setAccessible(true);
        $p = (new \ReflectionClass(OpenAiCompatibleProvider::class))->newInstanceWithoutConstructor();
        $this->assertSame('', $m->invoke($p, []));
        $out = $m->invoke($p, ['medical' => true]);
        $this->assertStringContainsString('Khi nào nên gặp bác sĩ', $out);
        $this->assertStringContainsString('không thay thế tư vấn', $out);
        // nội dung cố định phải khớp chính luật MED01
        $text = 'Khi nào nên gặp bác sĩ? Hãy đi khám. Thông tin trong bài chỉ mang tính tham khảo, không thay thế tư vấn, chẩn đoán hay điều trị của bác sĩ.';
        $ctx = new \ReflectionClass(\App\Services\Content\Quality\Rules\MedicalSafetyRule::class);
        $this->assertSame(1, preg_match($ctx->getConstant('SEE_DOCTOR'), $text));
        $this->assertSame(1, preg_match($ctx->getConstant('DISCLAIMER'), $text));
    }
}
