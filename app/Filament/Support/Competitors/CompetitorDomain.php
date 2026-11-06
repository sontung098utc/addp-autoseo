<?php

namespace App\Filament\Support\Competitors;

use InvalidArgumentException;

/**
 * Chuẩn hóa + kiểm tra THẬT tên miền đối thủ do người dùng nhập. Chỉ xét cú pháp, KHÔNG phân giải DNS và
 * KHÔNG truy cập tên miền (màn này không crawl đối thủ). IP thuần, localhost và hậu tố nội bộ bị từ chối
 * để không lọt địa chỉ nội bộ/metadata vào bất kỳ bước truy vấn nào sau này.
 */
final class CompetitorDomain
{
    public const MAX_COMPETITORS = 10;

    private const BLOCKED_SUFFIXES = ['localhost', 'local', 'internal', 'test', 'invalid', 'lan', 'intranet', 'corp', 'home'];

    /**
     * @param  list<string>  $existing  tên miền đã theo dõi (đã chuẩn hóa)
     *
     * @throws InvalidArgumentException
     */
    public static function validate(string $input, array $existing, ?string $ownBaseUrl = null): string
    {
        $d = mb_strtolower(trim($input));
        if ($d === '') {
            throw new InvalidArgumentException('Hãy nhập tên miền đối thủ.');
        }
        $d = (string) preg_replace('#^[a-z][a-z0-9+.\-]*://#', '', $d);
        $d = (string) preg_split('#[/?\#]#', $d, 2)[0];
        if (str_contains($d, '@')) {
            throw new InvalidArgumentException('Chỉ nhập tên miền, không kèm tài khoản hay mật khẩu.');
        }
        if (str_contains($d, ':')) {
            $ipv6 = filter_var(trim($d, '[]'), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);
            throw new InvalidArgumentException($ipv6 ? 'Không nhận địa chỉ IP, hãy nhập tên miền công khai.' : 'Chỉ nhập tên miền, không kèm cổng.');
        }
        $d = rtrim($d, '.');
        $d = (string) preg_replace('/^www\./', '', $d);

        if (filter_var($d, FILTER_VALIDATE_IP) || preg_match('/^[0-9.]+$/', $d)) {
            throw new InvalidArgumentException('Không nhận địa chỉ IP, hãy nhập tên miền công khai.');
        }
        if (! preg_match('/^(?:[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9\-]{0,60}[a-z0-9])$/', $d) || strlen($d) > 253) {
            throw new InvalidArgumentException('Tên miền không hợp lệ (chỉ gồm chữ không dấu, số, dấu gạch ngang và dấu chấm, ví dụ doithu.vn).');
        }
        $tld = substr($d, (int) strrpos($d, '.') + 1);
        if ($d === 'localhost' || in_array($tld, self::BLOCKED_SUFFIXES, true)) {
            throw new InvalidArgumentException('Không nhận tên miền nội bộ (localhost, .local, .internal, .test...).');
        }
        if ($ownBaseUrl !== null && $ownBaseUrl !== '') {
            $own = (string) preg_replace('/^www\./', '', mb_strtolower((string) (parse_url($ownBaseUrl, PHP_URL_HOST) ?: '')));
            if ($own !== '' && $own === $d) {
                throw new InvalidArgumentException('Đây là tên miền của chính website bạn, không thêm làm đối thủ.');
            }
        }
        if (in_array($d, $existing, true)) {
            throw new InvalidArgumentException("Đã theo dõi $d rồi.");
        }
        if (count($existing) >= self::MAX_COMPETITORS) {
            throw new InvalidArgumentException('Tối đa '.self::MAX_COMPETITORS.' đối thủ cho mỗi website. Hãy bỏ bớt trước khi thêm.');
        }

        return $d;
    }
}
