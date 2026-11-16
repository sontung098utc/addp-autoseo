<?php

namespace Tests\Unit;

use App\Services\Content\MedicalDisclaimer;
use App\Services\Content\Quality\Rules\MedicalSafetyRule;
use Tests\TestCase;

class MedicalDisclaimerTest extends TestCase
{
    public function test_adds_fixed_note_when_missing_and_matches_med01(): void
    {
        [$out, $added] = MedicalDisclaimer::ensure('<p>Nội dung.</p>');
        $this->assertTrue($added);
        $this->assertStringContainsString('autoseo-callout', $out);
        $this->assertSame(1, preg_match((new \ReflectionClassConstant(MedicalSafetyRule::class, 'DISCLAIMER'))->getValue(), strip_tags($out)));
    }

    public function test_no_duplicate_when_already_present_or_empty(): void
    {
        $has = '<p>Thông tin này chỉ mang tính tham khảo, không thay thế tư vấn của bác sĩ.</p>';
        $this->assertSame([$has, false], MedicalDisclaimer::ensure($has));
        $this->assertSame(['', false], MedicalDisclaimer::ensure(''));
    }
}
