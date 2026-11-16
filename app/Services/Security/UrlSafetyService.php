<?php

namespace App\Services\Security;

use InvalidArgumentException;

class UrlSafetyService
{
    /**
     * Dải IP cấm (Private, Loopback, Cloud Metadata, Link-Local, Multicast)
     */
    protected const BLOCKED_CIDR_RANGES = [
        '0.0.0.0/8',          // "This" Network
        '10.0.0.0/8',         // Private-Use (RFC 1918)
        '127.0.0.0/8',        // Loopback
        '100.64.0.0/10',      // Shared Address Space (RFC 6598)
        '169.254.0.0/16',     // Link Local & Cloud Metadata (169.254.169.254)
        '172.16.0.0/12',      // Private-Use (RFC 1918)
        '192.0.0.0/24',       // IETF Protocol Assignments
        '192.0.2.0/24',       // TEST-NET-1
        '192.88.99.0/24',     // 6to4 Relay Anycast
        '192.168.0.0/16',     // Private-Use (RFC 1918)
        '198.18.0.0/15',      // Benchmarking
        '198.51.100.0/24',    // TEST-NET-2
        '203.0.113.0/24',     // TEST-NET-3
        '224.0.0.0/4',        // Multicast
        '240.0.0.0/4',        // Reserved
        '255.255.255.255/32', // Limited Broadcast
    ];

    /**
     * Kiểm tra tính an toàn của URL trước khi gửi request (Chống SSRF triệt để)
     *
     * @param string $url
     * @param array $domainAllowlist Danh sách domain được phép (tùy chọn)
     * @return bool
     * @throws InvalidArgumentException
     */
    public function validateUrl(string $url, array $domainAllowlist = []): bool
    {
        if (empty($url)) {
            throw new InvalidArgumentException('URL không được để trống.');
        }

        $parts = parse_url($url);
        if (! $parts || ! isset($parts['scheme'])) {
            throw new InvalidArgumentException("URL không hợp lệ: {$url}");
        }

        $scheme = strtolower($parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException("Chỉ chấp nhận giao thức HTTP/HTTPS, nhận được: {$scheme}");
        }

        if (! isset($parts['host'])) {
            throw new InvalidArgumentException("URL thiếu thông tin host: {$url}");
        }

        $host = strtolower($parts['host']);

        // Chặn host dạng localhost
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            throw new InvalidArgumentException("Từ chối truy cập host nội bộ: {$host}");
        }

        // Kiểm tra domain allowlist nếu có yêu cầu
        if (! empty($domainAllowlist)) {
            $isAllowed = false;
            foreach ($domainAllowlist as $allowedDomain) {
                $allowedDomain = strtolower(trim($allowedDomain));
                if ($host === $allowedDomain || str_ends_with($host, '.' . $allowedDomain)) {
                    $isAllowed = true;
                    break;
                }
            }

            if (! $isAllowed) {
                throw new InvalidArgumentException("Tên miền '{$host}' không nằm trong danh sách được phép truy cập (domain allowlist).");
            }
        }

        // Phân giải IP từ hostname để chống SSRF (DNS Resolution Check)
        $ips = $this->resolveIps($host);
        if (empty($ips)) {
            throw new InvalidArgumentException("Không thể phân giải địa chỉ IP cho host: {$host}");
        }

        foreach ($ips as $ip) {
            if ($this->isBlockedIp($ip)) {
                throw new InvalidArgumentException("Địa chỉ IP '{$ip}' của host '{$host}' thuộc dải mạng nội bộ/bị chặn (SSRF Prevention).");
            }
        }

        return true;
    }

    /**
     * Base URL của nhà cung cấp AI: như validateUrl, nhưng cho phép ĐÚNG các địa chỉ "host:cổng" nằm trong danh sách cho phép
     * (config ai_models.private_base_url_allowlist, đặt qua AI_PRIVATE_BASE_URL_ALLOWLIST; mặc định rỗng). Dùng cho cầu nối nội bộ khi thử nghiệm.
     * Khớp nguyên văn host (IP hoặc tên) và cổng; không khớp thì áp luật chặn nội bộ như thường lệ.
     *
     * @throws InvalidArgumentException
     */
    public function validateAiBaseUrl(string $url): bool
    {
        $parts = parse_url($url);
        if ($parts && isset($parts['scheme'], $parts['host'], $parts['port']) && in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            $hostPort = strtolower($parts['host']).':'.$parts['port'];
            $allowed = array_map(fn ($v) => strtolower(trim((string) $v)), (array) config('ai_models.private_base_url_allowlist', []));
            if (in_array($hostPort, $allowed, true)) {
                return true;
            }
        }

        return $this->validateUrl($url);
    }

    /**
     * Phân giải danh sách IP từ hostname
     *
     * @param string $host
     * @return array
     */
    protected function resolveIps(string $host): array
    {
        // Nếu bản thân host đã là IP
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $ips = gethostbynamel($host);
        return $ips ?: [];
    }

    /**
     * Kiểm tra một IP có nằm trong danh sách cấm / IP nội bộ hay không
     *
     * @param string $ip
     * @return bool
     */
    public function isBlockedIp(string $ip): bool
    {
        // Kiểm tra filter chuẩn của PHP (loại trừ private range và reserved range)
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return true;
        }

        // Xử lý kiểm tra IPv4 CIDR
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $ipLong = ip2long($ip);
            if ($ipLong === false) {
                return true;
            }

            foreach (self::BLOCKED_CIDR_RANGES as $cidr) {
                [$subnet, $bits] = explode('/', $cidr);
                $subnetLong = ip2long($subnet);
                $mask = -1 << (32 - (int) $bits);
                $subnetMasked = $subnetLong & $mask;

                if (($ipLong & $mask) === $subnetMasked) {
                    return true;
                }
            }
        }

        // Xử lý IPv6 loopback và unique local
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            if ($ip === '::1' || str_starts_with(strtolower($ip), 'fe80:') || str_starts_with(strtolower($ip), 'fc00:') || str_starts_with(strtolower($ip), 'fd00:')) {
                return true;
            }

            // Chặn IPv4-mapped IPv6 (::ffff:127.0.0.1, ::ffff:169.254.169.254)
            if (str_starts_with(strtolower($ip), '::ffff:')) {
                $mappedIpv4 = substr($ip, 7);
                if (filter_var($mappedIpv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    return $this->isBlockedIp($mappedIpv4);
                }
            }
        }

        return false;
    }
}
