<?php

namespace Tests\Unit\Support;

use App\Services\Keywords\KeywordAiUnavailable;
use App\Support\UserFacingError;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Tests\TestCase;

class UserFacingErrorTest extends TestCase
{
    private const LEAKS = ['/home', 'storage/framework', 'file_put_contents', 'ErrorException', 'SQLSTATE', 'select *', 'vendor/'];

    private function assertClean(string $s): void
    {
        foreach (self::LEAKS as $l) {
            $this->assertStringNotContainsString($l, $s);
        }
    }

    public function test_loi_he_thong_thanh_cau_chung_co_ma_va_ghi_log(): void
    {
        Log::spy();
        $e = new \ErrorException('file_put_contents(/home/u/app/storage/framework/cache/data/ab/cd/x): Failed to open stream: No such file or directory');
        $out = UserFacingError::from($e, 'thu');
        $this->assertClean($out);
        $this->assertStringContainsString('Có lỗi hệ thống', $out);
        $this->assertMatchesRegularExpression('/Mã tham chiếu: ([A-Z0-9]{6})\)/', $out);
        preg_match('/Mã tham chiếu: (\w{6})/', $out, $m);
        Log::shouldHaveReceived('error')->withArgs(fn ($msg, $ctx) => str_contains($msg, $m[1]) && $ctx['ref'] === $m[1] && $ctx['context'] === 'thu' && str_contains($ctx['message'], 'file_put_contents'))->once();
    }

    public function test_query_exception_khong_lo_cau_sql(): void
    {
        $e = new QueryException('mysql', 'select * from users where id = ?', [1], new \Exception('SQLSTATE[HY000]: boom'));
        $this->assertClean(UserFacingError::from($e));
    }

    public function test_loi_nghiep_vu_an_toan_giu_nguyen(): void
    {
        $this->assertSame('Từ khóa đã có trong kế hoạch.', UserFacingError::from(new InvalidArgumentException('Từ khóa đã có trong kế hoạch.')));
        $this->assertSame('AI chưa sẵn sàng.', UserFacingError::from(new KeywordAiUnavailable('AI chưa sẵn sàng.')));
    }

    public function test_thong_diep_an_toan_nhung_chua_duong_dan_hoac_khoa_bi_thay(): void
    {
        foreach ([
            'Không ghi được /home/zunp/app/storage/x.json',
            'Gọi https://api.x.vn/v1?key=SECRET123 thất bại',
            'Lỗi App\\Services\\Foo\\Bar không rõ',
            'Token abcdef1234567890abcdef1234567890 sai',
        ] as $msg) {
            $out = UserFacingError::from(new InvalidArgumentException($msg));
            $this->assertStringContainsString('Có lỗi hệ thống', $out, $msg);
            $this->assertStringNotContainsString('SECRET123', $out);
            $this->assertStringNotContainsString('/home', $out);
        }
    }

    public function test_runtime_exception_tu_thu_vien_bi_che(): void
    {
        // ném từ ngoài app/ (thư mục tests) nên coi là lỗi thư viện/hệ thống
        $out = UserFacingError::from(new \RuntimeException('cURL error 7: Failed to connect to 10.0.0.5 port 443'));
        $this->assertStringContainsString('Có lỗi hệ thống', $out);
        $this->assertStringNotContainsString('10.0.0.5', $out);
    }
}
