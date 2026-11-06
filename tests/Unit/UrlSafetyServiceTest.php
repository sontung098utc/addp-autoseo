<?php

namespace Tests\Unit;

use App\Services\Security\UrlSafetyService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class UrlSafetyServiceTest extends TestCase
{
    protected UrlSafetyService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new UrlSafetyService();
    }

    public function test_it_blocks_empty_url(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('URL không được để trống.');

        $this->service->validateUrl('');
    }

    public function test_it_blocks_non_http_protocols(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Chỉ chấp nhận giao thức HTTP/HTTPS');

        $this->service->validateUrl('ftp://example.com/image.png');
    }

    public function test_it_blocks_file_protocol(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Chỉ chấp nhận giao thức HTTP/HTTPS');

        $this->service->validateUrl('file:///etc/passwd');
    }

    public function test_it_blocks_localhost(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Từ chối truy cập host nội bộ');

        $this->service->validateUrl('http://localhost/admin');
    }

    public function test_it_blocks_loopback_ip(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('thuộc dải mạng nội bộ/bị chặn');

        $this->service->validateUrl('http://127.0.0.1:8000/secret');
    }

    public function test_it_blocks_private_ip_ranges(): void
    {
        $privateIps = [
            '10.0.0.1',
            '172.16.0.1',
            '172.31.255.255',
            '192.168.1.1',
            '169.254.169.254', // AWS metadata
        ];

        foreach ($privateIps as $ip) {
            $this->assertTrue($this->service->isBlockedIp($ip), "IP {$ip} phải bị chặn.");
        }
    }

    public function test_it_blocks_ipv6_loopback_and_metadata(): void
    {
        $this->assertTrue($this->service->isBlockedIp('::1'));
        $this->assertTrue($this->service->isBlockedIp('::ffff:127.0.0.1'));
        $this->assertTrue($this->service->isBlockedIp('::ffff:169.254.169.254'));
    }

    public function test_it_allows_public_ip(): void
    {
        $this->assertFalse($this->service->isBlockedIp('8.8.8.8'));
        $this->assertFalse($this->service->isBlockedIp('1.1.1.1'));
    }

    public function test_it_enforces_domain_allowlist(): void
    {
        $allowlist = ['addp.vn', 'company.com'];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('không nằm trong danh sách được phép truy cập');

        $this->service->validateUrl('https://evil-site.com/image.jpg', $allowlist);
    }
}
