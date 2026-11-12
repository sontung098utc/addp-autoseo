<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Các test cũ kiểm từng luật P0 như luật chặn; chính sách "chỉ chặn điều nguy hiểm" có test riêng (QualityBlockingPolicyTest).
        config(['quality.blocking_codes' => 'ALL']);
    }
}
