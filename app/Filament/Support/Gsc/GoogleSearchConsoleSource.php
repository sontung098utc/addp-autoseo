<?php

namespace App\Filament\Support\Gsc;

use App\Models\Website;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Nguồn Search Console THẬT (Search Analytics API). Mỗi website một thuộc tính (sc-domain:... hoặc URL tiền tố).
 * Lỗi mạng/quyền KHÔNG trả số giả: ghi vào error() và trả rỗng để màn hiện "chưa có dữ liệu" kèm lý do.
 */
final class GoogleSearchConsoleSource implements GscSource
{
    private const DIMS = ['query' => ['date', 'query', 'page'], 'page' => ['date', 'page'], 'device' => ['date', 'device'], 'country' => ['date', 'country']];

    private int $fetched = 0;

    private ?string $error = null;

    public function __construct(private readonly string $property, private readonly GscAccessToken $auth) {}

    /** Thuộc tính Search Console cho website, hoặc null nếu chưa cấu hình khóa / website không có địa chỉ. */
    public static function forWebsite(?Website $site): ?self
    {
        $auth = GscAccessToken::fromConfig();
        $prop = $site ? self::propertyFor($site) : null;

        return $auth && $prop ? new self($prop, $auth) : null;
    }

    public static function propertyFor(Website $site): ?string
    {
        $host = strtolower((string) parse_url((string) $site->base_url, PHP_URL_HOST));
        $host = preg_replace('/^www\./', '', $host) ?? $host;
        if ($host === '') {
            return null;
        }
        foreach (explode(';', (string) config('gsc.properties')) as $pair) {
            [$h, $p] = array_pad(explode('=', $pair, 2), 2, '');
            if (strtolower(trim($h)) === $host && trim($p) !== '') {
                return trim($p);
            }
        }

        return config('gsc.property_type', 'domain') === 'url' ? 'https://'.$host.'/' : 'sc-domain:'.$host;
    }

    public function property(): string
    {
        return $this->property;
    }

    public function error(): ?string
    {
        return $this->error;
    }

    public function isEmpty(): bool
    {
        return $this->fetched === 0;
    }

    public function rows(string $dimension, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $dims = self::DIMS[$dimension] ?? null;
        if ($dims === null) {
            return [];
        }
        $ck = 'gsc:rows:'.md5($this->property.'|'.$dimension.'|'.$from->toDateString().'|'.$to->toDateString());
        $rows = Cache::get($ck);
        if (! is_array($rows)) {
            try {
                $rows = $this->fetch($dims, $from, $to);
            } catch (GscFailed $e) {
                $this->error = $e->getMessage();

                return [];
            }
            Cache::put($ck, $rows, now()->addMinutes((int) config('gsc.cache_minutes', 360)));
        }
        $this->fetched += count($rows);

        return $rows;
    }

    /** @param list<string> $dims @return list<array{date:string,key:string,page:?string,clicks:int,impressions:int,position:float}> @throws GscFailed */
    private function fetch(array $dims, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $url = str_replace('{site}', rawurlencode($this->property), (string) config('gsc.endpoint'));
        $limit = (int) config('gsc.row_limit', 25000);
        $out = [];
        for ($page = 0; $page < (int) config('gsc.max_pages', 8); $page++) {
            try {
                $res = Http::withToken($this->auth->get())->acceptJson()->timeout(60)->post($url, [
                    'startDate' => $from->toDateString(), 'endDate' => $to->toDateString(), 'dimensions' => $dims,
                    'rowLimit' => $limit, 'startRow' => $page * $limit, 'dataState' => 'final',
                ]);
            } catch (GscFailed $e) {
                throw $e;
            } catch (\Throwable $e) {
                throw new GscFailed('Không kết nối được Search Console: '.mb_substr($e->getMessage(), 0, 120));
            }
            if (! $res->successful()) {
                throw new GscFailed('Search Console trả lỗi HTTP '.$res->status().($res->status() === 403 ? ' (service account chưa được thêm vào thuộc tính '.$this->property.').' : '.'));
            }
            $batch = (array) ($res->json('rows') ?? []);
            foreach ($batch as $r) {
                $k = (array) ($r['keys'] ?? []);
                if (count($k) < count($dims) || ! isset($r['impressions'])) {
                    continue;
                }
                $isQuery = in_array('query', $dims, true);
                $out[] = [
                    'date' => (string) $k[0],
                    'key' => (string) $k[1],
                    'page' => $isQuery ? (string) ($k[2] ?? '') : (in_array('page', $dims, true) ? (string) $k[1] : null),
                    'clicks' => (int) round((float) ($r['clicks'] ?? 0)),
                    'impressions' => (int) round((float) $r['impressions']),
                    'position' => (float) ($r['position'] ?? 0),
                ];
            }
            if (count($batch) < $limit) {
                break;
            }
        }

        return $out;
    }
}
