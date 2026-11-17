<?php

namespace App\Services\Agent\Ai;

use App\Models\AiProvider;
use App\Models\AiProviderModel;
use App\Services\Security\UrlSafetyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * Lấy danh sách model THẬT của một tài khoản AI từ API của hãng (Gemini / Anthropic / OpenAI-tương thích), thay vì chỉ dựa vào danh mục cố định trong
 * config (model ra mới và ngừng liên tục). Kết quả chỉ để HIỂN THỊ và CHỌN: không bao giờ tự đổi model đã bật, bản gán vai trò hay định tuyến.
 *
 * Quy tắc (theo yêu cầu):
 * - model cũ còn hoạt động: giữ nguyên, không đụng tới bản gán vai trò;
 * - model biến mất khỏi danh sách của hãng: đánh dấu "gone" (hiện cảnh báo, nhất là khi đang được gán cho vai trò), KHÔNG xóa;
 * - model mới xuất hiện: đánh dấu "Mới" trong sync_badge_days ngày rồi tự hết dấu;
 * - lần gọi thất bại (mạng, khóa sai, cầu nối không có /models): KHÔNG đổi trạng thái nào, để lỗi mạng tạm thời không làm model bị coi là ngừng.
 */
final class AiModelSync
{
    private const GEMINI_BASE = 'https://generativelanguage.googleapis.com/v1beta/';

    private const ANTHROPIC_BASE = 'https://api.anthropic.com';

    public static function available(): bool
    {
        try {
            return Schema::hasTable('ai_provider_models');
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Gọi API danh sách model. @return list<array{id: string, name: ?string, kind: string}>
     *
     * @throws RuntimeException thông báo đã ẩn khóa
     */
    public function fetch(AiProvider $p): array
    {
        if (! $p->hasKey()) {
            throw new RuntimeException('Tài khoản chưa có khóa API.');
        }
        $key = (string) $p->api_key;
        try {
            $rows = match ($p->kind) {
                'gemini' => $this->fetchGemini($key),
                'anthropic' => $this->fetchAnthropic($p, $key),
                default => $this->fetchOpenAiCompat($p, $key),
            };
        } catch (RuntimeException $e) {
            throw new RuntimeException($this->mask($e->getMessage(), $key), (int) $e->getCode());
        } catch (Throwable $e) {
            throw new RuntimeException('Không gọi được hãng để lấy danh sách model: '.$this->mask($e->getMessage(), $key));
        }
        $exclude = (string) config('ai_models.sync_exclude_pattern');
        $out = [];
        foreach ($rows as $r) {
            $id = trim((string) ($r['id'] ?? ''));
            if ($id === '' || ! AiModelCatalog::validCustomName($id) || ($exclude !== '' && preg_match($exclude, $id))) {
                continue;
            }
            $out[$id] = ['id' => $id, 'name' => isset($r['name']) && trim((string) $r['name']) !== '' ? mb_substr(trim((string) $r['name']), 0, 200) : null, 'kind' => AiModelCatalog::inferKind($id)];
        }
        if ($out === []) {
            throw new RuntimeException('Hãng không trả model nào dùng được (danh sách rỗng).');
        }

        return array_values($out);
    }

    /** @return list<array{id: string, name: ?string}> */
    private function fetchGemini(string $key): array
    {
        $out = [];
        $page = null;
        for ($i = 0; $i < 5; $i++) {
            $resp = Http::acceptJson()->timeout(25)->connectTimeout(8)->withHeaders(['x-goog-api-key' => $key])
                ->get(self::GEMINI_BASE.'models', array_filter(['pageSize' => 200, 'pageToken' => $page]));
            $this->assertOk($resp);
            foreach ((array) $resp->json('models', []) as $m) {
                if (! in_array('generateContent', (array) ($m['supportedGenerationMethods'] ?? []), true)) {
                    continue;
                }
                $out[] = ['id' => preg_replace('#^models/#', '', (string) ($m['name'] ?? '')), 'name' => $m['displayName'] ?? null];
            }
            $page = $resp->json('nextPageToken');
            if (! $page) {
                break;
            }
        }

        return $out;
    }

    /** @return list<array{id: string, name: ?string}> */
    private function fetchAnthropic(AiProvider $p, string $key): array
    {
        $base = rtrim((string) ($p->base_url ?: self::ANTHROPIC_BASE), '/');
        $this->assertSafeBase($p->base_url);
        $url = str_ends_with($base, '/v1') ? $base.'/models' : $base.'/v1/models';
        $resp = Http::acceptJson()->timeout(25)->connectTimeout(8)->withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])->get($url, ['limit' => 1000]);
        $this->assertOk($resp);

        return array_map(fn ($m) => ['id' => (string) ($m['id'] ?? ''), 'name' => $m['display_name'] ?? null], (array) $resp->json('data', []));
    }

    /** @return list<array{id: string, name: ?string}> */
    private function fetchOpenAiCompat(AiProvider $p, string $key): array
    {
        if (blank($p->base_url)) {
            throw new RuntimeException('Tài khoản chưa có Base URL.');
        }
        $this->assertSafeBase($p->base_url);
        $resp = Http::acceptJson()->timeout(25)->connectTimeout(8)->withToken($key)->get(rtrim((string) $p->base_url, '/').'/models');
        $this->assertOk($resp);

        return array_map(fn ($m) => ['id' => (string) ($m['id'] ?? ''), 'name' => $m['display_name'] ?? $m['name'] ?? null], (array) ($resp->json('data') ?? $resp->json('models') ?? []));
    }

    private function assertSafeBase(?string $base): void
    {
        if (blank($base)) {
            return;
        }
        try {
            app(UrlSafetyService::class)->validateAiBaseUrl((string) $base);
        } catch (\InvalidArgumentException) {
            throw new RuntimeException('Base URL không an toàn hoặc không được phép.');
        }
    }

    private function assertOk(\Illuminate\Http\Client\Response $resp): void
    {
        if ($resp->successful()) {
            return;
        }
        $why = match (true) {
            in_array($resp->status(), [401, 403], true) => 'khóa API không đúng hoặc không có quyền xem danh sách model',
            $resp->status() === 404 => 'hãng/cầu nối này không có API danh sách model',
            $resp->status() === 429 => 'hết hạn mức tạm thời',
            default => 'lỗi phía hãng',
        };
        throw new RuntimeException('Hãng trả mã '.$resp->status().': '.$why.'.', $resp->status());
    }

    private function mask(string $m, string $key): string
    {
        return mb_substr($key !== '' ? str_replace($key, '***', $m) : $m, 0, 220);
    }

    /**
     * Đồng bộ một tài khoản. Không đụng vào model đã bật hay bản gán vai trò.
     *
     * @return array{ok: bool, error: ?string, added: list<string>, gone: list<string>, back: list<string>, total: int}
     */
    public function sync(AiProvider $p): array
    {
        $res = ['ok' => false, 'error' => null, 'added' => [], 'gone' => [], 'back' => [], 'total' => 0];
        if (! self::available()) {
            $res['error'] = 'Chưa có bảng lưu model (cần chạy migration).';

            return $res;
        }
        try {
            $fetched = $this->fetch($p);
        } catch (RuntimeException $e) {
            $res['error'] = $e->getMessage();

            return $res;
        }
        $days = max(1, (int) config('ai_models.sync_badge_days', 7));
        $now = now();
        DB::transaction(function () use ($p, $fetched, $days, $now, &$res) {
            $existing = AiProviderModel::query()->where('ai_provider_id', $p->id)->get()->keyBy('model_id');
            $first = $existing->isEmpty() && $p->models_synced_at === null; // lần đầu: coi toàn bộ là nền, không dán nhãn "Mới" cho cả danh sách
            $seen = [];
            foreach ($fetched as $m) {
                $seen[$m['id']] = true;
                $row = $existing->get($m['id']);
                if ($row === null) {
                    AiProviderModel::create(['ai_provider_id' => $p->id, 'model_id' => $m['id'], 'name' => $m['name'], 'kind' => $m['kind'], 'status' => 'active',
                        'first_seen_at' => $now, 'last_seen_at' => $now, 'new_until' => $first ? null : $now->copy()->addDays($days)]);
                    if (! $first) {
                        $res['added'][] = $m['id'];
                    }

                    continue;
                }
                $wasGone = $row->status === 'gone';
                $row->fill(['name' => $m['name'] ?? $row->name, 'kind' => $m['kind'], 'status' => 'active', 'last_seen_at' => $now, 'gone_at' => null, 'gone_until' => null]);
                if ($wasGone) {
                    $row->new_until = $now->copy()->addDays($days); // quay lại: báo như model mới
                    $res['back'][] = $m['id'];
                }
                $row->save();
            }
            // Model từng thấy nhưng nay vắng mặt => ngưng. KHÔNG xóa dòng, KHÔNG đụng bản gán vai trò.
            foreach ($existing as $id => $row) {
                if (! isset($seen[$id]) && $row->status === 'active') {
                    $row->update(['status' => 'gone', 'gone_at' => $now, 'gone_until' => $now->copy()->addDays($days), 'new_until' => null]);
                    $res['gone'][] = $id;
                }
            }
            // Model ĐÃ BẬT của tài khoản mà hãng không còn liệt kê (kể cả lần đầu): đánh dấu ngưng để cảnh báo, không tự bỏ bật.
            foreach (AiModelCatalog::enabledModels($p) as $em) {
                if (! isset($seen[$em['id']]) && ! $existing->has($em['id'])) {
                    AiProviderModel::updateOrCreate(['ai_provider_id' => $p->id, 'model_id' => $em['id']], ['name' => $em['name'], 'kind' => $em['kind'], 'status' => 'gone',
                        'first_seen_at' => $now, 'last_seen_at' => null, 'gone_at' => $now, 'gone_until' => $now->copy()->addDays($days), 'new_until' => null]);
                    $res['gone'][] = $em['id'];
                }
            }
            $p->forceFill(['models_synced_at' => $now])->save();
        });
        $res['ok'] = true;
        $res['total'] = count($fetched);

        return $res;
    }

    /**
     * Dấu hiệu theo model của một tài khoản: 'new' (còn trong thời hạn) hoặc 'gone' (còn ngưng thì còn cảnh báo, vì có thể đang được gán cho vai trò).
     *
     * @return array<string, string> model_id => new|gone
     */
    public static function flags(AiProvider $p): array
    {
        if (! self::available()) {
            return [];
        }
        $out = [];
        foreach (AiProviderModel::query()->where('ai_provider_id', $p->id)->get() as $r) {
            if ($r->status === 'gone') {
                $out[$r->model_id] = 'gone';
            } elseif ($r->new_until !== null && $r->new_until->isFuture()) {
                $out[$r->model_id] = 'new';
            }
        }

        return $out;
    }

    /**
     * Model đang hoạt động theo lần đồng bộ gần nhất mà tài khoản CHƯA bật, ưu tiên model mới (còn dấu "Mới") lên đầu.
     *
     * @return list<array{id: string, name: string, kind: string, is_new: bool}>
     */
    public static function discoveredNotEnabled(AiProvider $p): array
    {
        if (! self::available()) {
            return [];
        }
        $enabled = array_column(AiModelCatalog::enabledModels($p), 'id');
        $out = [];
        foreach (AiProviderModel::query()->where('ai_provider_id', $p->id)->where('status', 'active')->orderByDesc('first_seen_at')->orderBy('model_id')->get() as $r) {
            if (in_array($r->model_id, $enabled, true)) {
                continue;
            }
            $out[] = ['id' => $r->model_id, 'name' => $r->name ?: $r->model_id, 'kind' => $r->kind, 'is_new' => $r->new_until !== null && $r->new_until->isFuture()];
        }
        usort($out, fn ($a, $b) => [$b['is_new'], $a['id']] <=> [$a['is_new'], $b['id']]);

        return $out;
    }

    /** Model đang hoạt động (theo hãng) của tài khoản, để làm lựa chọn trong form sửa. @return array<string, array{name: string, kind: string}> */
    public static function activeModels(AiProvider $p): array
    {
        if (! self::available()) {
            return [];
        }
        $out = [];
        foreach (AiProviderModel::query()->where('ai_provider_id', $p->id)->where('status', 'active')->orderBy('model_id')->get() as $r) {
            $out[$r->model_id] = ['name' => $r->name ?: $r->model_id, 'kind' => $r->kind];
        }

        return $out;
    }

    /** Có model nào đang được gán cho vai trò mà đã ngưng không. @return list<array{role: string, slot: string, provider: string, model: string}> */
    public static function goneInUse(): array
    {
        if (! self::available() || ! Schema::hasTable('agent_role_assignments')) {
            return [];
        }
        $gone = AiProviderModel::query()->where('status', 'gone')->get(['ai_provider_id', 'model_id'])->map(fn ($r) => $r->ai_provider_id.'|'.$r->model_id)->flip();
        if ($gone->isEmpty()) {
            return [];
        }
        $out = [];
        foreach (\App\Models\AgentRoleAssignment::query()->where('enabled', true)->get() as $a) {
            foreach ([['primary', $a->provider_id, $a->model], ['fallback', $a->fallback_provider_id, $a->fallback_model]] as [$slot, $pid, $model]) {
                if ($pid && $model && $gone->has($pid.'|'.$model)) {
                    $out[] = ['role' => (string) $a->role_key, 'slot' => $slot, 'provider' => (string) (AiProvider::query()->whereKey($pid)->value('name') ?? '#'.$pid), 'model' => (string) $model];
                }
            }
        }

        return $out;
    }
}
