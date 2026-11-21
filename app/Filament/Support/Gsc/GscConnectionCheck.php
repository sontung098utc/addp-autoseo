<?php

namespace App\Filament\Support\Gsc;

use App\Models\GscConnection;
use App\Models\Website;
use Carbon\CarbonImmutable;

/** Trạng thái và kiểm tra kết nối Search Console của một website. Dùng chung cho trang Website và lệnh gsc:check. */
final class GscConnectionCheck
{
    /**
     * Trạng thái tĩnh, KHÔNG gọi Google.
     *
     * @return array{connected:bool,label:?string,kind:?string,property:?string,oauth_ready:bool,oauth_email:?string,has_service_account:bool}
     */
    public static function describe(Website $site): array
    {
        $src = GoogleSearchConsoleSource::forWebsite($site);

        return [
            'connected' => $src !== null,
            'label' => $src?->authLabel(),
            'kind' => $src?->authKind(),
            'property' => $src?->property() ?? GoogleSearchConsoleSource::propertyFor($site),
            'oauth_ready' => GscOAuth::configured(),
            'oauth_email' => GscConnection::active()?->google_email,
            'has_service_account' => GscAccessToken::fromConfig() !== null,
        ];
    }

    /**
     * Gọi thật một lượt 7 ngày (bỏ qua cache).
     *
     * @return array{level:'none'|'error'|'empty'|'ok',message:string,label:?string,property:?string,rows:int}
     */
    public static function run(Website $site): array
    {
        $src = GoogleSearchConsoleSource::forWebsite($site);
        if (! $src) {
            return ['level' => 'none', 'label' => null, 'property' => GoogleSearchConsoleSource::propertyFor($site), 'rows' => 0,
                'message' => 'Chưa có cách đăng nhập nào dùng được: không có file service account đọc được và không có kết nối Google (hoặc thiếu GSC_OAUTH_CLIENT_ID/SECRET, hoặc website không có địa chỉ).'];
        }
        $to = CarbonImmutable::now()->subDays(3);
        $from = $to->subDays(6);
        $rows = $src->withoutCache()->rows('page', $from, $to);
        $base = ['label' => $src->authLabel(), 'property' => $src->property(), 'rows' => count($rows)];
        if ($src->error() !== null) {
            return $base + ['level' => 'error', 'message' => $src->error()];
        }
        if ($rows === []) {
            return $base + ['level' => 'empty', 'message' => 'Gọi được nhưng chưa có dòng dữ liệu nào trong 7 ngày ('.$from->toDateString().' đến '.$to->toDateString().').'];
        }

        return $base + ['level' => 'ok', 'message' => count($rows).' dòng (trang x ngày), '.array_sum(array_column($rows, 'clicks')).' clicks, '.array_sum(array_column($rows, 'impressions')).' impressions trong 7 ngày.'];
    }
}
