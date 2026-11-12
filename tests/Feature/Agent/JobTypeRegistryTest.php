<?php

namespace Tests\Feature\Agent;

use App\Services\Agent\Pipeline\JobTypeRegistry;
use Tests\TestCase;

/** G1: sổ loại việc theo chuỗi làm ra một bài hợp lệ và đủ bộ. */
class JobTypeRegistryTest extends TestCase
{
    public function test_registry_has_no_problems(): void
    {
        $this->assertSame([], JobTypeRegistry::problems());
    }

    public function test_all_seven_job_types_exist(): void
    {
        $ids = array_column(JobTypeRegistry::all(), 'id');
        sort($ids);
        $expected = ['article_refresh', 'article_write', 'brain_build', 'content_plan', 'market_research', 'measure', 'site_audit'];
        $this->assertSame($expected, $ids);
    }

    public function test_every_chain_link_except_publish_has_a_job_type(): void
    {
        foreach (array_keys(JobTypeRegistry::CHAIN) as $link) {
            if ($link === 'publish') {
                continue;
            }
            $this->assertNotEmpty(JobTypeRegistry::forLink($link), "mắt xích '$link' chưa có loại việc");
        }
    }

    public function test_article_write_stage_labels(): void
    {
        $stages = JobTypeRegistry::stageLabels('article_write');
        $this->assertSame(['guard', 'risk', 'brief', 'research', 'write', 'images', 'grade', 'repair', 'assemble', 'handoff'], array_keys($stages));
        $this->assertSame(array_keys($stages), array_keys(JobTypeRegistry::stageLabels('article_write', true)));
    }
}
