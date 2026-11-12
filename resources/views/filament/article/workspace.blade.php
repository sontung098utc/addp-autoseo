@php
    use App\Filament\Pages\BrainLibraryPage;
    use App\Filament\Pages\BrainPage;
    use App\Filament\Pages\BrandProfilePage;
    use App\Filament\Pages\ContentPlanPage;
    use App\Filament\Pages\KeywordsPage;
    use App\Filament\Pages\WeeklySchedule;
    use App\Filament\Resources\AgentRunResource;
    use App\Filament\Resources\ArticleResource;
    use App\Filament\Resources\ArticleResource\Pages\ViewArticle as W;
    use App\Filament\Resources\ArticleVersionResource as V;
    use App\Filament\Resources\CampaignResource;
    use App\Filament\Resources\PublicationResource;
    use App\Filament\Support\Agent\RunActivity;
    use App\Filament\Support\Keywords\KeywordIntent;
    use App\Filament\Support\Review\ArticleRows;
    use App\Filament\Support\Schedule\VnTime;
    use App\Filament\Support\StatusPresenter;
    use App\Models\BrandProfile;
    use App\Models\KnowledgeItem;
    use Illuminate\Support\Str;

    $dash = fn ($v) => filled($v) ? $v : '—';
    $num = fn ($v) => $v === null ? '—' : number_format((int) $v, 0, ',', '.');
    $statusLabel = StatusPresenter::label($article->workflow_status, 'article');
    $statusColor = StatusPresenter::color($article->workflow_status, 'article');
    $qCounts = $quality?->counts();
    $canApprove = \App\Filament\Support\Review\ReviewActions::canApprove();
    $sources = array_values((array) ($latest?->sources_cited ?? []));
    $knowledgeGap = (bool) ($research['knowledge']['gap'] ?? false);
    $riskLevel = $readiness['risk']['level'] ?? 'low';
    // Bài rủi ro cao hoặc bước research ghi kho thiếu: điểm hình thức không nói gì về độ đúng.
    $noGrounding = $knowledgeGap || ($riskLevel === 'high' && ($readiness['knowledge']['count'] ?? 0) === 0);
    $buildPage = class_exists(\App\Filament\Pages\BrainBuildPage::class) && \App\Filament\Pages\BrainBuildPage::canAccess() ? \App\Filament\Pages\BrainBuildPage::getUrl() : null;
    $riskLabel = ArticleResource::RISK_LABELS[$riskLevel] ?? $riskLevel;
    $money = fn ($v) => ArticleResource::vnd($v);
    $imageNote = $research['images']['warning'] ?? null;
    $links = array_values(array_filter((array) ($research['internal_links']['inserted'] ?? [])));
    $images = $versions->flatMap->mediaAssets->unique('id');
    $pubDone = $publications->firstWhere('status', 'published');
    $pubNext = $publications->first(fn ($p) => $p->status !== 'published');

    // MỘT nút hành động chính (nếu người dùng làm được), đặt ở đầu tab Tổng quan.
    $latestUrl = $latest && $canOpenVersion ? V::getUrl('view', ['record' => $latest]) : null;
    $primary = match (true) {
        $outlineHold !== null && $canOutline => ['action' => 'approveOutline', 'label' => 'Duyệt dàn ý và viết tiếp'],
        $live !== null => ['tab' => 'activity', 'label' => 'Theo dõi phiên chạy'],
        $latest === null => ArticleResource::canAssignToAgent($article) ? ['action' => 'run_agent', 'label' => 'Giao đội AI'] : null,
        $article->workflow_status === 'needs_review' && $canApprove => $latestUrl ? ['url' => $latestUrl, 'label' => 'Đọc để duyệt'] : ['tab' => 'content', 'label' => 'Đọc để duyệt'],
        $article->workflow_status === 'approved' && $canApprove => ['action' => 'schedule', 'label' => 'Xếp lịch'],
        in_array($article->workflow_status, ['scheduled', 'published'], true) => ['tab' => 'publish', 'label' => 'Xem lịch xuất bản'],
        ArticleResource::canAssignToAgent($article) => ['action' => 'run_agent', 'label' => 'Giao đội AI'],
        ArticleResource::primaryActionFor($article) === 'submit_review' => ['action' => 'submit_review', 'label' => 'Gửi duyệt'],
        default => null,
    };

    // Chuỗi làm ra một bài: mỗi mắt xích lấy kết quả của mắt xích trước. state: done | run | warn | todo.
    $liveLink = match (true) {
        $liveStage === null => null,
        in_array($liveStage, ['guard', 'risk', 'brief'], true) => 'research',
        in_array($liveStage, ['write', 'repair'], true) => 'write',
        default => 'check',
    };
    $reviewed = in_array($article->workflow_status, ['approved', 'scheduled', 'published'], true);
    $chain = [
        ['key' => 'keyword', 'tab' => 'keyword', 'label' => 'Từ khóa', 'state' => $keyword ? 'done' : 'warn',
            'text' => $keyword ? ($keyword->volume !== null ? $num($keyword->volume).' lượt tìm/tháng' : 'Có trong kho, chưa có số liệu tìm kiếm') : 'Chưa có trong kho từ khóa'],
        ['key' => 'plan', 'tab' => 'keyword', 'label' => 'Kế hoạch', 'state' => ($article->origin || $campaign || $cluster) ? 'done' : 'todo',
            'text' => $campaign ? 'Chiến dịch: '.$campaign->name : ($article->origin ? 'Từ '.(W::ORIGINS[$article->origin] ?? $article->origin) : ($cluster ? 'Cụm: '.$cluster->name : 'Bài lẻ, chưa thuộc kế hoạch nào'))],
        ['key' => 'research', 'tab' => 'research', 'label' => 'Nghiên cứu', 'state' => $outline !== [] || $usages->isNotEmpty() ? ($knowledgeGap ? 'warn' : 'done') : 'todo',
            'text' => $outline === [] && $usages->isEmpty() ? 'Chưa chạy' : $usages->count().' mục kiến thức · '.count($sources).' nguồn trích'.($knowledgeGap ? ' · kho thiếu kiến thức' : '')],
        ['key' => 'write', 'tab' => 'content', 'label' => 'Viết', 'state' => $latest ? 'done' : 'todo',
            'text' => $latest ? $versions->count().' phiên bản · '.$images->count().' ảnh' : 'Chưa có bản nào'],
        ['key' => 'check', 'tab' => 'content', 'label' => 'Kiểm và duyệt', 'state' => ! $latest ? 'todo' : ($reviewed ? 'done' : ($quality->hasBlocking() || $article->workflow_status === 'rejected' ? 'warn' : 'todo')),
            'text' => ! $latest ? 'Chưa có gì để kiểm' : 'P0 '.$qCounts['P0'].' · P1 '.$qCounts['P1'].' · '.$statusLabel],
        ['key' => 'publish', 'tab' => 'publish', 'label' => 'Xuất bản', 'state' => $pubDone ? 'done' : ($publications->contains(fn ($p) => filled($p->last_error)) ? 'warn' : 'todo'),
            'text' => $pubDone ? 'Đã đăng '.VnTime::format($pubDone->published_at_utc) : ($pubNext ? 'Lịch '.VnTime::format($pubNext->scheduled_at_utc) : 'Chưa có lịch')],
    ];
    $stateLabel = ['done' => 'Xong', 'run' => 'Đang chạy', 'warn' => 'Cần chú ý', 'todo' => 'Chưa làm'];

    $tabs = [
        ['key' => 'overview', 'label' => 'Tổng quan'],
        ['key' => 'keyword', 'label' => 'Từ khóa và kế hoạch'],
        ['key' => 'research', 'label' => 'Nghiên cứu và nguồn', 'tone' => $knowledgeGap ? 'warn' : null],
        ['key' => 'content', 'label' => 'Nội dung', 'count' => $versions->count() ?: null, 'tone' => $quality?->hasBlocking() ? 'danger' : null],
        ['key' => 'activity', 'label' => 'Hoạt động', 'count' => $runs->count() ?: null, 'tone' => $live ? 'info' : null],
        ['key' => 'publish', 'label' => 'Xuất bản', 'count' => $publications->count() ?: null],
    ];
@endphp
<x-filament-panels::page>
    <style>
        .aw-meta{display:flex;flex-wrap:wrap;gap:.4rem .5rem;align-items:center;margin-top:-.4rem}
        .aw-chip{font-size:.75rem;padding:.1rem .55rem;border-radius:999px;border:1px solid var(--ss-border,rgba(128,128,128,.35))}
        a.aw-chip{text-decoration:underline}
        .aw-next{border:1px solid var(--ss-border,rgba(128,128,128,.35));border-radius:.5rem;background:rgba(128,128,128,.06);padding:.5rem .75rem;font-size:.95rem;margin:0;display:flex;flex-wrap:wrap;gap:.5rem 1rem;align-items:center;justify-content:space-between}
        .aw-next p{margin:0}
        .aw-cta{display:inline-block;font-size:.875rem;font-weight:700;padding:.4rem 1rem;border-radius:.5rem;background:var(--ss-teal-700,#0F6B5C);color:#fff;cursor:pointer;text-decoration:none;white-space:nowrap}
        .aw-cta:hover{filter:brightness(1.1)}
        .aw-chips{display:flex;flex-wrap:wrap;gap:.4rem .5rem;align-items:center}
        .aw-chip--warn{border-color:#d97706;color:#b45309}
        .aw-grid{display:grid;gap:1rem;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));align-items:start}
        .aw-stack{display:grid;gap:1rem}
        .aw-table{width:100%;border-collapse:collapse;font-size:.875rem}
        .aw-table th,.aw-table td{text-align:left;padding:.45rem .6rem;border-bottom:1px solid var(--ss-border,rgba(128,128,128,.25));vertical-align:top}
        .aw-table th{font-weight:600;opacity:.8;white-space:nowrap}
        .aw-table tr.is-current td{font-weight:600}
        .aw-scroll{overflow-x:auto}
        @media (max-width:1400px){.aw-opt{display:none}}
        .aw-muted{opacity:.75;font-size:.85rem}
        .aw-list{margin:0;padding-left:1.1rem;list-style:disc;display:grid;gap:.25rem}
        .aw-link{text-decoration:underline}
        button.aw-link{min-height:1.5rem;padding-block:.15rem}
        .aw-btn{display:inline-block;font-size:.8rem;font-weight:600;padding:.25rem .8rem;border-radius:.5rem;border:1px solid currentColor;white-space:nowrap}
        .aw-split{display:grid;gap:1rem;grid-template-columns:minmax(0,2fr) minmax(260px,1fr);align-items:start}
        @media (max-width:1024px){.aw-split{grid-template-columns:minmax(0,1fr)}}
        .aw-note{border:1px solid rgba(128,128,128,.35);border-radius:.5rem;background:rgba(128,128,128,.06);padding:.4rem .7rem;font-size:.875rem;margin:.6rem 0 0}
        .aw-head{display:flex;flex-wrap:wrap;gap:.5rem 1rem;align-items:baseline;justify-content:space-between}
        /* Chuỗi mắt xích */
        .aw-chain{display:grid;gap:.5rem;grid-template-columns:repeat(6,minmax(0,1fr))}
        @media (max-width:1100px){.aw-chain{grid-template-columns:repeat(3,minmax(0,1fr))}}
        @media (max-width:640px){.aw-chain{grid-template-columns:minmax(0,1fr)}}
        .aw-node{all:unset;box-sizing:border-box;cursor:pointer;display:block;border:1px solid var(--ss-border,rgba(128,128,128,.35));border-top-width:3px;border-radius:.5rem;padding:.5rem .65rem;min-width:0}
        .aw-node:hover{background:rgba(128,128,128,.08)}
        .aw-node:focus-visible{outline:2px solid var(--ss-focus,currentColor);outline-offset:2px}
        .aw-node__k{font-size:.72rem;opacity:.75}
        .aw-node__t{font-weight:700;font-size:.9rem;display:flex;gap:.35rem;align-items:center}
        .aw-node__d{font-size:.78rem;opacity:.85;overflow-wrap:anywhere}
        .aw-node--done{border-top-color:#16a34a}
        .aw-node--warn{border-top-color:#d97706}
        .aw-node--run{border-top-color:#0ea5e9}
        .aw-node--todo{border-top-style:dashed}
        /* Phiên đang chạy */
        .aw-live{border:1px solid rgb(14 165 233 / .45);border-radius:.6rem;padding:.75rem;display:grid;gap:.6rem}
        .aw-rail{display:flex;flex-wrap:wrap;gap:.35rem}
        .aw-stage{font-size:.78rem;padding:.2rem .6rem;border-radius:.4rem;border:1px solid var(--ss-border,rgba(128,128,128,.35))}
        .aw-stage--done{border-color:#16a34a}
        .aw-stage--run{font-weight:700}
        .aw-steps{margin:0;padding:0;list-style:none;display:grid;gap:.3rem;font-size:.85rem}
        .aw-steps li{display:flex;gap:.5rem;align-items:baseline}
        .aw-thumbs{display:flex;flex-wrap:wrap;gap:.6rem}
        .aw-thumbs img{width:120px;height:80px;object-fit:cover;border-radius:.4rem}
        .aw-thumbs figcaption{font-size:.72rem;max-width:120px;opacity:.8}
    </style>
    <div class="aw-stack" data-testid="article-workspace" @if ($live) wire:poll.{{ $pollSeconds }}s @endif>
        <div class="aw-meta" data-testid="aw-meta">
            <x-filament::badge :color="$statusColor">{{ $statusLabel }}</x-filament::badge>
            @if ($needsExpert)
                @if (in_array($article->workflow_status, ['approved', 'scheduled', 'published'], true))
                    {{-- Đã duyệt rồi: không còn "chờ", nhưng nhắc đây là chủ đề cần chuyên môn để người sau đọc ghi chú duyệt. --}}
                    <x-filament::badge color="gray" data-testid="aw-expert">Chủ đề cần chuyên môn · xem ghi chú duyệt</x-filament::badge>
                @else
                    <x-filament::badge color="warning" data-testid="aw-expert">{{ ArticleResource::EXPERT_LABEL }}</x-filament::badge>
                @endif
            @endif
            @if ($live)
                <span class="ss-live-pill" data-testid="aw-live-pill"><span class="ss-spin" aria-hidden="true"></span>Đội AI đang chạy · {{ W::runStages()[$liveStage] ?? 'sắp xong' }}</span>
            @endif
            @if ($article->website)<span class="aw-chip">{{ $article->website->name }}</span>@endif
        </div>

        <x-studio.tabs :persist="$showPreflight ? null : 'bai-viet-v4-'.$article->id" :default="$tabDefault" :tabs="$tabs">
            {{-- Tổng quan: việc tiếp theo + một nút chính, tiến trình, thông tin bài. Giải thích cất vào (i). --}}
            <x-studio.panel key="overview">
                <div class="aw-stack">
                    @if (! empty($stopInfo))
                        <p class="aw-note" data-testid="aw-stopped" style="color:#b91c1c;border:1px solid #b91c1c;border-radius:.5rem;padding:.5rem .75rem"><x-filament::icon icon="heroicon-o-exclamation-triangle" style="width:1.1rem;height:1.1rem;display:inline;vertical-align:-.2em" /> Hệ thống đang DỪNG KHẨN CẤP ({{ $stopInfo['reason'] ?? 'không ghi lý do' }}): đội AI sẽ không chạy cho đến khi được khôi phục ở Lịch xuất bản.</p>
                    @endif
                    @if ($outlineHold !== null)
                        <section class="ss-review-card" data-testid="aw-outline-hold" style="border-color:#d97706">
                            <div class="aw-head" style="justify-content:flex-start;gap:.4rem">
                                <h2 class="ss-review-card__title">Dàn ý chờ bạn xem</h2>
                                <x-help tone="warning" title="Vì sao đội AI dừng ở đây">
                                    <p>Chiến dịch đặt điểm dừng sau dàn ý. Đội AI chưa viết, nên chưa tốn token viết. Sửa dàn ý rồi bấm "Duyệt dàn ý và viết tiếp"; đội AI sẽ viết đúng theo dàn ý đã duyệt.</p>
                                    <p>"Giao lại từ đầu" bỏ dàn ý này và lập dàn ý mới.</p>
                                </x-help>
                            </div>
                            @if ($outlineHold['outline'] === [])
                                <p class="aw-muted">Dàn ý trống.</p>
                            @else
                                <ol data-testid="aw-outline-items" style="margin:.4rem 0 .4rem 1.2rem;list-style:decimal">
                                    @foreach ($outlineHold['outline'] as $item)<li>{{ $item }}</li>@endforeach
                                </ol>
                            @endif
                            @if ($canOutline)
                                <div class="aw-chips" style="align-items:center;gap:.75rem">
                                    <x-filament::button size="sm" icon="heroicon-o-check" wire:click="mountAction('approveOutline')" data-testid="aw-outline-approve">Duyệt dàn ý và viết tiếp</x-filament::button>
                                    <button type="button" class="aw-link" data-testid="aw-outline-redo" wire:click="mountAction('regenerateOutline')">Giao lại từ đầu</button>
                                </div>
                            @else
                                <p class="aw-muted">Cần người viết trở lên để sửa và duyệt dàn ý.</p>
                            @endif
                        </section>
                    @endif
                    @if (filled($article->guidance))
                        <p class="aw-muted" data-testid="aw-guidance"><strong>Chỉ dẫn cho đội AI:</strong> {{ \Illuminate\Support\Str::limit($article->guidance, 160) }}</p>
                    @endif
                    @if ($showPreflight)
                        <section class="ss-review-card" data-testid="aw-preflight">
                            <div class="aw-head" style="justify-content:flex-start;gap:.4rem">
                                <h2 class="ss-review-card__title">AI sẽ tự chuẩn bị</h2>
                                <x-help title="Trước khi viết, AI sẽ">
                                    @if (! empty($readiness['auto_actions']))
                                        <ul data-testid="pf-auto">@foreach ($readiness['auto_actions'] as $act)<li>{{ $act }}</li>@endforeach</ul>
                                    @endif
                                    <p>
                                        Kho có {{ $readiness['knowledge']['count'] }} mục kiến thức đã duyệt khớp chủ đề@if ($readiness['knowledge']['count'] > 0): {{ implode('; ', array_map(fn ($i) => $i['title'], array_slice($readiness['knowledge']['items'], 0, 3))) }}@endif.
                                    </p>
                                    @if (! empty($readiness['risk']['reasons']))<p>Rủi ro: {{ implode(' ', array_slice($readiness['risk']['reasons'], 0, 2)) }}</p>@endif
                                    @if ($readiness['brand']['missing'] !== [])<p>Hồ sơ doanh nghiệp còn thiếu: {{ implode(', ', $readiness['brand']['missing']) }}.</p>@endif
                                    @if ($readiness['brains']['attached'] !== [])<p>Trí tuệ đang gắn: {{ implode(', ', array_column($readiness['brains']['attached'], 'name')) }}.</p>@endif
                                    <p>Ngân sách phiên tối đa {{ $money($readiness['cost']['budget_vnd']) }}.</p>
                                    @foreach ($readiness['brains']['suggested'] as $sg)
                                        <p data-testid="pf-suggested">Trí tuệ sẽ tự gắn khi giao: «{{ $sg['name'] }}» · {{ $sg['reason'] }}</p>
                                    @endforeach
                                    @if (! empty($readiness['needs_expert']))
                                        <p data-testid="pf-expert">Chủ đề này cần người có chuyên môn: sau khi viết sẽ chuyển cho người có chuyên môn xem lại.</p>
                                    @endif
                                </x-help>
                            </div>
                            <div class="aw-chips" data-testid="pf-chips">
                                <span class="aw-chip @if ($riskLevel === 'high') aw-chip--warn @endif" data-testid="pf-risk">Rủi ro: <strong>{{ $riskLabel }}</strong></span>
                                {{-- Chỉ chip có số liệu giúp quyết định; số 0 / "chưa có" đã có ghi chú kèm liên kết bên dưới. --}}
                                @if ($readiness['knowledge']['count'] > 0)<span class="aw-chip" data-testid="pf-kb">Kho: {{ $readiness['knowledge']['count'] }} mục</span>@endif
                                @if ($readiness['products']['count'] > 0)<span class="aw-chip" data-testid="pf-products">Sản phẩm: {{ $readiness['products']['count'] }}</span>@endif
                                @if ($readiness['brand']['percent'] > 0)<span class="aw-chip" data-testid="pf-brand">Hồ sơ: {{ $readiness['brand']['percent'] }}%</span>@endif
                                @if ($readiness['cost']['estimate_vnd'] !== null)<span class="aw-chip" data-testid="pf-cost">Ước tính: ~{{ $money($readiness['cost']['estimate_vnd']) }}</span>@endif
                            </div>
                            @if (! empty($autoAttached))
                                <div class="aw-muted" data-testid="pf-auto-attached" style="margin-top:.5rem">Đã tự gắn {{ count($autoAttached) }} Trí tuệ: {{ collect($autoAttached)->pluck('name')->implode(', ') }}
                                    <x-help title="Trí tuệ đã tự gắn (hoàn tác được)">
                                        @foreach ($autoAttached as $at)
                                            <p data-testid="pf-auto-row">«{{ $at['name'] }}» lúc {{ \Illuminate\Support\Carbon::parse($at['at'])->timezone('Asia/Ho_Chi_Minh')->format('d/m H:i') }}
                                                @if ($canUndoAttach && $article->website) · <button type="button" class="aw-link" data-testid="pf-undo" wire:click="mountAction('undoAutoAttach', @js(['brain' => $at['brain_id']]))">Hoàn tác</button>@endif
                                            </p>
                                        @endforeach
                                    </x-help>
                                </div>
                            @endif
                            @foreach ($readiness['issues'] as $is)
                                @if ($is['severity'] !== 'info' || in_array($is['code'], ['brand_incomplete'], true))
                                    <p class="aw-note aw-muted" data-testid="pf-issue" data-code="{{ $is['code'] }}" data-severity="{{ $is['severity'] }}">
                                        {{ $is['message'] }}
                                        @if ($is['code'] === 'kb_empty')
                                            @if (BrainLibraryPage::canAccess()) <a class="aw-link" href="{{ BrainLibraryPage::getUrl() }}">Mở Kho trí tuệ</a>@endif
                                            @if ($buildPage) · <a class="aw-link" href="{{ $buildPage }}">Xây kho từ website</a>@endif
                                        @elseif ($is['code'] === 'brand_incomplete' && BrandProfilePage::canAccess())
                                            <a class="aw-link" href="{{ BrandProfilePage::getUrl() }}">Mở hồ sơ doanh nghiệp</a>
                                        @elseif ($is['code'] === 'no_brain' && BrainLibraryPage::canAccess())
                                            <a class="aw-link" href="{{ BrainLibraryPage::getUrl() }}">Mở Kho trí tuệ</a>
                                        @endif
                                    </p>
                                @endif
                            @endforeach
                        </section>
                    @endif
                    <section class="ss-review-card" data-testid="aw-chain">
                        <div class="aw-head" style="justify-content:flex-start;gap:.4rem">
                            <h2 class="ss-review-card__title">Tiến trình bài</h2>
                            <x-help title="Bài được làm ra thế nào"><p>Mỗi bước dùng kết quả của bước trước. Bấm vào một bước để xem chi tiết.</p></x-help>
                        </div>
                        <div class="aw-chain">
                            @foreach ($chain as $i => $n)
                                @php $st = $liveLink === $n['key'] ? 'run' : $n['state']; @endphp
                                <button type="button" class="aw-node aw-node--{{ $st }} @if ($st === 'run') ss-stage-live @endif" data-link="{{ $n['key'] }}" data-state="{{ $st }}" x-on:click="set(@js($n['tab']))">
                                    <div class="aw-node__k">Bước {{ $i + 1 }} · {{ $stateLabel[$st] }}</div>
                                    <div class="aw-node__t">@if ($st === 'run')<span class="ss-spin" aria-hidden="true"></span>@endif{{ $n['label'] }}</div>
                                    @if ($st !== 'todo' || ! in_array($n['text'], ['Chưa chạy', 'Chưa có bản nào', 'Chưa có gì để kiểm', 'Chưa có lịch'], true))<div class="aw-node__d">{{ $n['text'] }}</div>@endif
                                </button>
                            @endforeach
                        </div>
                    </section>
                    @if ($totalCost > 0)
                        <div class="aw-chips" data-testid="aw-stats">
                            <span class="aw-chip">Chi phí AI {{ V::formatVnd($totalCost) }}</span>
                            <x-help title="Số liệu của bài">
                                <p>{{ $versions->count() }} phiên bản · {{ $runs->count() }} phiên AI · {{ $num($totalTokens) }} token</p>
                                <p>Tạo: {{ VnTime::format($article->created_at) }} · Cập nhật: {{ VnTime::format($article->updated_at) }}</p>
                            </x-help>
                        </div>
                    @endif
                    <div data-testid="aw-info-form">
                        <x-filament-panels::form wire:submit="save">
                            {{ $this->form }}
                            <x-filament-panels::form.actions :actions="$this->getCachedFormActions()" :full-width="$this->hasFullWidthFormActions()" />
                        </x-filament-panels::form>
                    </div>
                </div>
            </x-studio.panel>

            {{-- Từ khóa và kế hoạch: bài sinh ra từ đâu, vì sao đáng viết, nằm cạnh những bài nào. --}}
            <x-studio.panel key="keyword">
                <div class="aw-stack">
                    <div class="aw-grid">
                        <section class="ss-review-card" data-testid="aw-keyword">
                            <div class="aw-head">
                                <h2 class="ss-review-card__title">Từ khóa chính: {{ $article->primary_keyword }}
                                    @if ($keyword === null || $keywordMatched || $keyword->volume === null)
                                        <x-help title="Về số liệu từ khóa">
                                            @if ($keyword === null)<p>Chưa có lượt tìm, độ khó hay cụm. Bài được tạo mà không qua bước nghiên cứu từ khóa.</p>@endif
                                            @if ($keyword && $keywordMatched)<p>Bài chưa được gắn trực tiếp với từ khóa này; số liệu được dò theo tên trong kho từ khóa.</p>@endif
                                            @if ($keyword && $keyword->volume === null)<p>Từ khóa chưa có số liệu tìm kiếm, nên chưa biết mức độ đáng viết. Nhập số liệu ở kho từ khóa (CSV hoặc Search Console).</p>@endif
                                        </x-help>
                                    @endif
                                </h2>
                                @if (KeywordsPage::canAccess())<a class="aw-link aw-muted" href="{{ KeywordsPage::getUrl() }}">Mở kho từ khóa</a>@endif
                            </div>
                            @if ($keyword)
                                <dl class="ss-review-dl">
                                    <dt>Lượt tìm mỗi tháng</dt><dd>{{ $num($keyword->volume) }}</dd>
                                    <dt>Độ khó</dt><dd>{{ $keyword->difficulty === null ? '—' : $keyword->difficulty.'/100' }}</dd>
                                    <dt>Ý định tìm kiếm</dt><dd>{{ KeywordIntent::label($keyword->intent) }}</dd>
                                    <dt>Nguồn từ khóa</dt><dd>{{ W::KEYWORD_SOURCES[$keyword->source] ?? $dash($keyword->source) }}</dd>
                                    <dt>Tình trạng trong kho</dt><dd>{{ W::KEYWORD_STATUSES[$keyword->status] ?? $dash($keyword->status) }}</dd>
                                </dl>
                            @else
                                <p>Từ khóa này chưa có trong kho từ khóa.</p>
                            @endif
                            <dl class="ss-review-dl" style="margin-top:.6rem">
                                <dt>Từ khóa phụ</dt><dd>{{ $dash(implode(', ', (array) $article->secondary_keywords)) }}</dd>
                                <dt>Ý định ghi trên bài</dt><dd>{{ KeywordIntent::label($article->search_intent) }}</dd>
                            </dl>
                        </section>
                        <section class="ss-review-card" data-testid="aw-plan">
                            <div class="aw-head">
                                <h2 class="ss-review-card__title">Kế hoạch</h2>
                                @if (ContentPlanPage::canAccess())<a class="aw-link aw-muted" href="{{ ContentPlanPage::getUrl() }}">Mở kế hoạch nội dung</a>@endif
                            </div>
                            <dl class="ss-review-dl">
                                <dt>Loại việc</dt><dd>{{ $article->kind === 'refresh' ? 'Làm mới bài cũ' : 'Viết bài mới' }}</dd>
                                <dt>Bài sinh ra từ</dt><dd>{{ $article->origin ? (W::ORIGINS[$article->origin] ?? $article->origin) : 'Không ghi nguồn (tạo tay)' }}@if (filled($article->origin_ref)) <span class="aw-muted">({{ $article->origin_ref }})</span>@endif</dd>
                                <dt>Mức ưu tiên</dt><dd>{{ $dash($article->priority) }}</dd>
                                <dt>Chiến dịch</dt><dd>@if ($campaign)<a class="aw-link" href="{{ CampaignResource::getUrl('edit', ['record' => $campaign]) }}">{{ $campaign->name }}</a>@else Không thuộc chiến dịch nào @endif</dd>
                                <dt>Cụm chủ đề</dt><dd>{{ $cluster?->name ?? $dash($article->topic_cluster) }}</dd>
                            </dl>
                        </section>
                    </div>
                    @if ($cluster)
                        <section class="ss-review-card" data-testid="aw-cluster">
                            <h2 class="ss-review-card__title">Cụm “{{ $cluster->name }}” <x-help title="Về cụm chủ đề"><p>Các từ khóa cùng cụm nên được phủ bằng các bài liên kết nhau. Từ nào chưa có bài là chỗ còn trống.</p></x-help></h2>
                            <div class="aw-scroll">
                                <table class="aw-table">
                                    <thead><tr><th>Từ khóa</th><th>Lượt tìm/tháng</th><th>Độ khó</th><th>Bài viết</th></tr></thead>
                                    <tbody>
                                        @foreach ($clusterKeywords as $k)
                                            <tr @class(['is-current' => $keyword && $k->id === $keyword->id])>
                                                <td>{{ $k->keyword }}@if ($keyword && $k->id === $keyword->id) <span class="aw-muted">(bài này)</span>@endif</td>
                                                <td>{{ $num($k->volume) }}</td>
                                                <td>{{ $dash($k->difficulty) }}</td>
                                                <td>
                                                    @forelse ($k->articles as $ka)
                                                        @if ($ka->id === $article->id) Bài này @else <a class="aw-link" href="{{ ArticleResource::getUrl('view', ['record' => $ka]) }}">Bài #{{ $ka->id }} · {{ StatusPresenter::label($ka->workflow_status, 'article') }}</a> @endif
                                                    @empty
                                                        <span class="aw-muted">Chưa có bài</span>
                                                    @endforelse
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                            @if ($clusterArticles->isNotEmpty())
                                <p style="margin-top:.6rem"><strong>Bài khác trong cụm:</strong>
                                    @foreach ($clusterArticles as $ca)
                                        <a class="aw-link" href="{{ ArticleResource::getUrl('view', ['record' => $ca]) }}">{{ $ca->primary_keyword }}</a>@if (! $loop->last), @endif
                                    @endforeach
                                </p>
                            @endif
                        </section>
                    @endif
                </div>
            </x-studio.panel>

            {{-- Nghiên cứu và nguồn: AI đã dựa vào những gì để viết bản mới nhất. --}}
            <x-studio.panel key="research">
                <div class="aw-stack">
                    @if ($knowledgeGap)
                        <div class="aw-note" data-testid="aw-gap" style="color:#b45309;margin:0">Bài này chưa có căn cứ từ kho kiến thức. <x-help tone="warning" title="Vì sao cần xem lại"><p>{{ ArticleResource::GAP_NOTE }}</p></x-help></div>
                    @endif
                    <div class="aw-grid">
                        <section class="ss-review-card" data-testid="aw-knowledge">
                            <h2 class="ss-review-card__title">Từ Kho trí tuệ <x-help title="Nguồn được kiểm soát"><p>Hệ thống hiện chưa tự tìm nguồn trên web hay từ tổ chức chuyên ngành; nguồn duy nhất được kiểm soát là Kho trí tuệ và hồ sơ doanh nghiệp.</p></x-help></h2>
                            @if ($brains === [])
                                <p>Chưa gắn Trí tuệ nào.</p>
                            @else
                                <p class="aw-muted">Trí tuệ đang dùng:
                                    @foreach ($brains as $b)
                                        <a class="aw-link" href="{{ BrainPage::getUrl(['brain' => $b->id]) }}">{{ $b->name }}</a>@if (! $loop->last), @endif
                                    @endforeach
                                </p>
                            @endif
                            @if ($buildPage)
                                <p style="margin-top:.5rem"><a class="aw-link" data-testid="aw-find-sources" href="{{ \App\Filament\Pages\BrainBuildPage::getUrl(['topic' => (string) $article->primary_keyword]) }}">Tìm nguồn cho chủ đề này</a></p>
                            @endif
                            @if ($usages->isNotEmpty())
                                <ul class="aw-list" style="margin-top:.5rem">
                                    @foreach ($usages as $u)
                                        <li>
                                            {{ $u->item?->title ?? 'Mục đã bị xóa' }}
                                            <span class="aw-muted">
                                                @if ($u->item) · {{ KnowledgeItem::TYPES[$u->item->type] ?? $u->item->type }} · {{ KnowledgeItem::STATUSES[$u->item->status] ?? $u->item->status }}@endif
                                                · bản {{ $u->item_version }}
                                                @if ($u->item?->source) · tài liệu gốc: {{ $u->item->source->title }}@endif
                                            </span>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </section>
                        @if (! empty($webSources))
                            <section class="ss-review-card" data-testid="aw-web-sources">
                                <h2 class="ss-review-card__title">Nguồn web đã đọc cho bài này</h2>
                                <ul class="aw-list">
                                    @foreach ($webSources as $w)
                                        <li>
                                            <a class="aw-link" href="{{ $w['url'] }}" target="_blank" rel="noopener noreferrer">{{ $w['title'] ?: $w['domain'] }}</a>
                                            <span class="aw-muted">· {{ $w['domain'] }}@if (! empty($w['fetched_at'])) · tải {{ \Illuminate\Support\Carbon::parse($w['fetched_at'])->format('d/m/Y') }}@endif · {{ ($w['trust'] ?? '') === 'trusted' ? 'tin cậy' : 'chưa rõ' }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            </section>
                        @endif
                        <section class="ss-review-card" data-testid="aw-products">
                            <h2 class="ss-review-card__title">Sản phẩm liên quan của công ty</h2>
                            @if ($products->isEmpty())
                                <div>Chưa có sản phẩm nào trong kho. @if (BrainLibraryPage::canAccess())<a class="aw-link" href="{{ BrainLibraryPage::getUrl() }}">Thêm ở Kho trí tuệ</a>@endif <x-help title="Vì sao cần sản phẩm"><p>Thêm mục loại Sản phẩm (hoặc dán trang sản phẩm) ở Kho trí tuệ để bài nhắc đúng sản phẩm.</p></x-help></div>
                            @else
                                <ul class="aw-list">
                                    @foreach ($products as $u)
                                        <li>
                                            {{ $u->item?->entity ?: $u->item?->title }}
                                            <span class="aw-muted">· {{ KnowledgeItem::STATUSES[$u->item?->status] ?? $u->item?->status }}</span>
                                            @if (! empty($u->item?->source?->url))
                                                · <a class="aw-link" href="{{ $u->item->source->url }}" target="_blank" rel="noopener">trang nguồn</a>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </section>
                        <section class="ss-review-card" data-testid="aw-brand">
                            <div class="aw-head">
                                <h2 class="ss-review-card__title">Từ hồ sơ doanh nghiệp</h2>
                                @if (BrandProfilePage::canAccess())<a class="aw-link aw-muted" href="{{ BrandProfilePage::getUrl() }}">Mở hồ sơ</a>@endif
                            </div>
                            @if ($brand)
                                <dl class="ss-review-dl">
                                    <dt>Lĩnh vực</dt><dd>{{ $dash($brand->business_field) }}</dd>
                                    <dt>Khách hàng</dt><dd>{{ $dash($brand->audience) }}</dd>
                                    <dt>Giọng viết</dt><dd>{{ BrandProfile::VOICES[$brand->voice] ?? $dash($brand->voice) }}</dd>
                                    <dt>Thông điệp chính</dt><dd>{{ count((array) $brand->key_messages) }} mục</dd>
                                    <dt>Từ cấm</dt><dd>{{ count((array) $brand->forbidden_terms) }} mục</dd>
                                    <dt>Điều được phép khẳng định</dt><dd>{{ count((array) $brand->allowed_claims) }} mục</dd>
                                </dl>
                            @else
                                <div>Website này chưa có hồ sơ doanh nghiệp. <x-help title="Hệ quả"><p>AI viết mà không có giọng thương hiệu, thông điệp chính hay danh sách từ cấm.</p></x-help></div>
                            @endif
                        </section>
                        @php $citable = array_values(array_filter((array) ($research['citable_sources'] ?? []))); @endphp
                        @if ($sources !== [] || $citable !== [])
                        <section class="ss-review-card" data-testid="aw-sources">
                            <h2 class="ss-review-card__title">Nguồn AI nêu trong bài @if ($sources !== [])<x-help tone="warning" title="Cần kiểm trước khi đăng"><p>Các nguồn này do AI tự nêu, hệ thống chưa kiểm chứng. Người duyệt cần kiểm trước khi đăng.</p></x-help>@endif</h2>
                            @if ($sources !== [])
                                <ul class="aw-list">
                                    @foreach ($sources as $source)
                                        <li>{{ is_array($source) ? implode(' — ', array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $source)) : (string) $source }}</li>
                                    @endforeach
                                </ul>
                            @endif
                            @if ($citable !== [])
                                <p style="margin-top:.5rem">Nguồn được phép trích: {{ count($citable) }} ({{ implode(', ', array_map(fn ($c) => is_array($c) ? (string) ($c['title'] ?? $c['name'] ?? $c['url'] ?? '') : (string) $c, array_slice($citable, 0, 6))) }})</p>
                            @endif
                        </section>
                        @endif
                        @if ($outline !== [])
                        <section class="ss-review-card" data-testid="aw-outline">
                            <h2 class="ss-review-card__title">Dàn ý AI lập</h2>
                            @if ($outline !== [])
                                <ol class="aw-list" style="list-style:decimal">@foreach ($outline as $o)<li>{{ $o }}</li>@endforeach</ol>
                            @endif
                            @if ($links !== [])
                                <p style="margin-top:.6rem"><strong>Liên kết nội bộ đã chèn:</strong> {{ count($links) }}</p>
                            @endif
                        </section>
                        @endif
                    </div>
                </div>
            </x-studio.panel>

            {{-- Nội dung: danh sách phiên bản; bấm Xem để sang trang chi tiết phiên bản (đọc, so sánh, kiểm chất lượng, duyệt). --}}
            <x-studio.panel key="content">
                <div class="aw-stack">
                    <section class="ss-review-card">
                        <div class="aw-head" style="justify-content:flex-start;gap:.4rem">
                            <h2 class="ss-review-card__title">Các phiên bản</h2>
                            @if ($latest)
                                <x-help title="Đọc điểm và duyệt">
                                    <p data-testid="aw-content-score-note">Điểm hình thức ({{ $qCounts['P0'] }} lỗi P0 · {{ $qCounts['P1'] }} P1 · {{ $qCounts['P2'] }} P2): {{ ArticleResource::SCORE_NOTE }}</p>
                                    <p>Bấm Xem để đọc toàn bộ nội dung, thẻ SEO, kiểm chất lượng và so sánh với bản trước. Các nút duyệt ở đầu trang áp dụng cho bản mới nhất.</p>
                                </x-help>
                            @endif
                        </div>
                        @if ($latest && $noGrounding)<div class="aw-note" data-testid="aw-content-gap" style="color:#b45309">Bài chưa có căn cứ từ kho kiến thức. <x-help tone="warning" title="Vì sao cần xem lại"><p>{{ ArticleResource::GAP_NOTE }}</p></x-help></div>@endif
                        @if ($versions->isEmpty())
                            <p>Bài chưa có nội dung.</p>
                        @else
                            <div class="aw-scroll">
                                <table class="aw-table" data-testid="aw-versions">
                                    <thead><tr><th>Bản</th><th>Tiêu đề</th><th>Trạng thái</th><th>Chưa đạt</th><th>Số từ</th><th>Ảnh</th><th>Tạo bởi</th><th>Tạo lúc</th><th></th></tr></thead>
                                    <tbody>
                                        @foreach ($versions as $v)
                                            @php $q = $versionQuality[$v->id]; $vr = $versionRun->get($v->id); @endphp
                                            <tr @class(['is-current' => $loop->first]) data-version="{{ $v->version }}">
                                                <td>v{{ $v->version }}@if ($loop->first) <span class="aw-muted">(mới nhất)</span>@endif</td>
                                                <td>
                                                    {{ $dash($v->title) }}
                                                    @if (filled($v->review_notes))<div class="aw-muted">Ghi chú duyệt: {{ Str::limit($v->review_notes, 160) }}</div>@endif
                                                </td>
                                                <td>{{ StatusPresenter::label($v->review_status, 'article') }}@if ($v->reviewer)<div class="aw-muted">{{ $v->reviewer->name }}</div>@endif</td>
                                                <td>P0 {{ $q['P0'] }} · P1 {{ $q['P1'] }} · P2 {{ $q['P2'] }}</td>
                                                <td>{{ $num(count(preg_split('/\s+/u', trim(strip_tags((string) $v->html_content)), -1, PREG_SPLIT_NO_EMPTY))) }}</td>
                                                <td>{{ $v->mediaAssets->count() }}</td>
                                                <td>@if ($vr && AgentRunResource::canViewAny())<a class="aw-link" href="{{ AgentRunResource::getUrl('view', ['record' => $vr]) }}">Phiên AI #{{ $vr->id }}</a>@elseif ($vr) Phiên AI #{{ $vr->id }} @else {{ $v->ai_model ? 'AI' : 'Người viết' }} @endif</td>
                                                <td>{{ VnTime::format($v->created_at) }}</td>
                                                <td>@if ($canOpenVersion)<a class="aw-btn" data-testid="aw-version-open" href="{{ V::getUrl('view', ['record' => $v]) }}">Xem</a>@endif
                                                    @if (! $loop->last)<button type="button" class="aw-btn" data-testid="aw-compare-open" wire:click="mountAction('compare', @js(['version' => $v->id]))">So với bản trước</button>@endif</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </section>
                    @if ($latest)
                        <section class="ss-review-card" data-testid="aw-images">
                            <h2 class="ss-review-card__title">Ảnh của bài</h2>
                            {{-- Bản mới nhất luôn hiện (kể cả khi chưa có ảnh, kèm lý do); bản cũ chỉ hiện nếu có ảnh. --}}
                            @foreach ($versions as $v)
                                @if ($v->is($latest) || $v->mediaAssets->isNotEmpty())
                                    @if (! $v->is($latest))<p class="aw-muted" style="margin-top:.75rem">Bản v{{ $v->version }}</p>@endif
                                    @include('filament.article.images-gallery', ['version' => $v])
                                @endif
                            @endforeach
                            @if ($imageNote)<div class="aw-muted" style="margin-top:.5rem">Ảnh từ thư viện <x-help title="Lần chọn ảnh gần nhất"><p>{{ $imageNote }}.</p></x-help></div>@endif
                        </section>
                    @endif
                </div>
            </x-studio.panel>

            {{-- Hoạt động: các phiên chạy của đội AI; phiên đang chạy hiện từng chặng theo thời gian thực. --}}
            <x-studio.panel key="activity">
                <div class="aw-stack">
                    @if ($live)
                        @php $stageKeys = array_keys(W::runStages()); $at = array_search($liveStage, $stageKeys, true); @endphp
                        <section class="aw-live" data-testid="aw-live">
                            <div class="aw-head">
                                <span class="ss-live-pill"><span class="ss-spin" aria-hidden="true"></span>Phiên #{{ $live->id }} đang chạy · {{ RunActivity::formatElapsed(RunActivity::elapsedSeconds($live)) }}</span>
                                @if (AgentRunResource::canViewAny())<a class="aw-link aw-muted" href="{{ AgentRunResource::getUrl('view', ['record' => $live]) }}">Xem diễn biến đầy đủ</a>@endif
                            </div>
                            <div class="aw-rail" data-testid="aw-rail">
                                @foreach (W::runStages() as $code => $label)
                                    @php $done = $live->steps->contains('stage', $code) && $code !== $liveStage; @endphp
                                    <span @class(['aw-stage', 'aw-stage--run ss-stage-live' => $code === $liveStage, 'aw-stage--done' => $done]) data-stage="{{ $code }}" @if ($code === $liveStage) data-live-stage="{{ $code }}" @endif>
                                        @if ($code === $liveStage)<span class="ss-spin" aria-hidden="true"></span>@elseif ($done)<x-filament::icon icon="heroicon-o-check" style="width:.85rem;height:.85rem;display:inline;vertical-align:-.15em" />@endif {{ $label }}
                                    </span>
                                @endforeach
                            </div>
                            @if ($live->steps->isNotEmpty())
                                <ul class="aw-steps">
                                    @foreach ($live->steps->sortByDesc('seq')->take(5) as $s)
                                        <li @class(['ss-step-new' => $loop->first]) wire:key="aw-step-{{ $s->id }}">
                                            <span class="aw-muted">{{ VnTime::format($s->finished_at ?? $s->started_at ?? $s->created_at) }}</span>
                                            <span>{{ $s->title }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </section>
                    @endif
                    <section class="ss-review-card">
                        <h2 class="ss-review-card__title">Các phiên chạy của đội AI @if ($runs->isNotEmpty())<x-help title="Điểm hình thức"><p data-testid="aw-score-note">{{ ArticleResource::SCORE_NOTE }}</p></x-help>@endif</h2>
                        @if ($runs->isEmpty())
                            <p>Bài này chưa được giao cho đội AI.</p>
                        @else
                            
                            <div class="aw-scroll">
                                <table class="aw-table" data-testid="aw-runs">
                                    <thead><tr><th>Phiên</th><th>Kết quả</th><th class="aw-opt">Điểm hình thức</th><th class="aw-opt">Tạo ra bản</th><th class="aw-opt">AI</th><th class="aw-opt">Vòng sửa</th><th>Chi phí</th><th>Bắt đầu</th><th></th></tr></thead>
                                    <tbody>
                                        @foreach ($runs as $r)
                                            @php $isLive = $live && $r->id === $live->id; $fv = $r->final_version_id ? $versions->firstWhere('id', $r->final_version_id) : null; @endphp
                                            <tr>
                                                <td>#{{ $r->id }}</td>
                                                <td>
                                                    @if ($isLive)<span class="ss-running"><span class="ss-spin" aria-hidden="true"></span>Đang chạy · {{ W::runStages()[$liveStage] ?? 'sắp xong' }}</span>
                                                    @else {{ AgentRunResource::STATUS[$r->status][0] ?? $r->status }}
                                                    @endif
                                                    @if ($r->outcome_reason)<div class="aw-muted">{{ Str::limit($r->outcome_reason, 140) }}</div>@endif
                                                </td>
                                                <td class="aw-opt">{{ ArticleRows::score($r) ?? '—' }}</td>
                                                <td class="aw-opt">{{ $fv ? 'v'.$fv->version : '—' }}</td>
                                                <td class="aw-opt">{{ $r->ai_mode === 'real' ? 'AI thật' : 'AI giả lập' }}</td>
                                                <td class="aw-opt">{{ (int) $r->repair_rounds }}</td>
                                                <td>{{ V::formatVnd($r->cost_vnd) }}</td>
                                                <td>{{ VnTime::format($r->started_at ?? $r->created_at) }}</td>
                                                <td>@if (AgentRunResource::canViewAny())<a class="aw-link" href="{{ AgentRunResource::getUrl('view', ['record' => $r]) }}">Xem diễn biến</a>@endif</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </section>
                </div>
            </x-studio.panel>

            {{-- Xuất bản: bản nào lên website nào, lúc nào, kết quả ra sao. --}}
            <x-studio.panel key="publish">
                <section class="ss-review-card">
                    <div class="aw-head">
                        <h2 class="ss-review-card__title">Lịch xuất bản của bài <x-help title="Dừng riêng bài này"><p>"Tạm dừng bài này" chỉ giữ lượt đăng của bài này, các bài khác vẫn đăng. Dừng khẩn cấp cho cả hệ thống ở trang Lịch xuất bản.</p></x-help></h2>
                        <span class="aw-muted">
                            @if (WeeklySchedule::canAccess())<a class="aw-link" href="{{ \App\Filament\Resources\PublicationResource::scheduleUrl() }}">Lịch tuần</a>@endif
                            @if (PublicationResource::canViewAny()) · <a class="aw-link" href="{{ PublicationResource::getUrl() }}">Tất cả lịch xuất bản</a>@endif
                        </span>
                    </div>
                    @if ($publications->isEmpty())
                        <div>{{ $article->workflow_status === 'approved' ? 'Bài đã duyệt, chưa có lịch.' : 'Chưa có lịch xuất bản.' }}@if ($article->workflow_status !== 'approved') <x-help title="Khi nào xếp lịch được"><p>Bài cần được duyệt trước khi xếp lịch.</p></x-help>@endif</div>
                    @else
                        <div class="aw-scroll">
                            <table class="aw-table" data-testid="aw-publications">
                                <thead><tr><th>Bản</th><th>Website</th><th>Trạng thái</th><th>Lịch (giờ VN)</th><th>Đã đăng lúc</th><th>Liên kết</th><th>Lỗi gần nhất</th></tr></thead>
                                <tbody>
                                    @foreach ($publications as $p)
                                        <tr>
                                            <td>v{{ $p->articleVersion?->version }}</td>
                                            <td>{{ $dash($p->website?->name) }}</td>
                                            <td>{{ StatusPresenter::label($p->status, 'publication') }}@if ($canHold)<div>@if ($p->status === 'scheduled')                                                         <button type="button" class="aw-link" data-testid="aw-pub-pause" wire:click="mountAction('pausePublication', @js(['id' => $p->id]))">Tạm dừng bài này</button>                                                     @elseif (in_array($p->status, ['paused', 'awaiting_confirm'], true))                                                         <button type="button" class="aw-link" data-testid="aw-pub-resume" wire:click="mountAction('resumePublication', @js(['id' => $p->id]))">{{ $p->status === 'awaiting_confirm' ? 'Xác nhận đăng' : 'Tiếp tục đăng' }}</button>                                                     @endif</div>@endif
                                                <div>
                                                    @if (auth()->user()?->isApprover() && in_array($p->status, ['scheduled', 'held', 'paused', 'awaiting_confirm'], true))<button type="button" class="aw-link" data-testid="aw-pub-reschedule" wire:click="mountAction('reschedulePublication', @js(['id' => $p->id]))">Đổi lịch</button> · @endif
                                                    <button type="button" class="aw-link" data-testid="aw-pub-why" wire:click="mountAction('whyPublication', @js(['id' => $p->id]))">{{ $p->status === 'published' ? 'Xem trạng thái đăng' : 'Vì sao chưa đăng?' }}</button>
                                                </div></td>
                                            <td>{{ VnTime::format($p->scheduled_at_utc) }}</td>
                                            <td>{{ $p->published_at_utc ? VnTime::format($p->published_at_utc) : '—' }}</td>
                                            <td>@if ($p->published_url && preg_match('#^https?://#i', $p->published_url))<a class="aw-link" href="{{ $p->published_url }}" target="_blank" rel="noopener noreferrer">Mở bài</a>@else — @endif</td>
                                            <td>{{ $dash($p->last_error ? Str::limit($p->last_error, 120) : null) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </section>
            </x-studio.panel>
        </x-studio.tabs>
    </div>
    <x-filament-panels::page.unsaved-data-changes-alert />
</x-filament-panels::page>
