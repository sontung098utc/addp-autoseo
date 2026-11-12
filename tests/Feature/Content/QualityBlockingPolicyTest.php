<?php

namespace Tests\Feature\Content;

use App\Models\ArticleVersion;
use App\Services\Content\Quality\ContentQualityChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Unit\QualityFixtures;

class QualityBlockingPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function codes(array $list): array
    {
        return array_values(array_unique(array_map(fn ($r) => $r->code, $list)));
    }

    public function test_default_policy_only_blocks_safety_critical_codes(): void
    {
        $short = new ArticleVersion(array_merge(QualityFixtures::goodAttrs('học bổng toàn phần'), ['html_content' => '<p>Bài quá ngắn.</p>']));

        config(['quality.blocking_codes' => 'ALL']);
        $this->assertContains('LEN01', $this->codes((new ContentQualityChecker)->check($short)->blocking()), 'ALL: độ dài ngắn vẫn chặn như trước');

        config(['quality.blocking_codes' => 'SAFE01,BAN01,EVID01,CITE01,FACT01']);
        $soft = (new ContentQualityChecker)->check($short);
        $this->assertNotContains('LEN01', $this->codes($soft->blocking()), 'mặc định: độ dài chỉ cảnh báo');
        $len = collect($soft->results)->first(fn ($r) => $r->code === 'LEN01' && ! $r->passed);
        $this->assertNotNull($len);
        $this->assertSame('P1', $len->severity);
        $this->assertStringContainsString('Chỉ cảnh báo', $len->message);

        $danger = new ArticleVersion(array_merge(QualityFixtures::goodAttrs('học bổng toàn phần'), ['html_content' => '<p>Nội dung <script>alert(1)</script> nguy hiểm.</p>']));
        $this->assertContains('SAFE01', $this->codes((new ContentQualityChecker)->check($danger)->blocking()), 'an toàn vẫn luôn chặn');
    }
}
