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

    // Một câu "việc tiếp theo" theo trạng thái, để người mới vào biết phải làm gì.
    $next = match (true) {
        $live !== null => 'Đội AI đang làm bài này. Theo dõi ở tab Hoạt động; trang tự làm mới.',
        $latest === null => 'Bài chưa có nội dung. Bấm Giao đội AI ở đầu trang để tạo bản nháp đầu tiên.',
        $article->workflow_status === 'needs_review' && $canApprove => 'Mở bản mới nhất ở tab Nội dung để đọc, rồi bấm Duyệt hoặc Từ chối ở đầu trang.',
        $article->workflow_status === 'needs_review' => 'Bài đang chờ người có quyền duyệt.',
        $article->workflow_status === 'approved' => 'Bài đã duyệt nhưng chưa có lịch. Bấm Xếp lịch ở đầu trang.',
        $article->workflow_status === 'rejected' => 'Bài cần sửa. Xem ghi chú duyệt ở tab Nội dung, rồi giao lại đội AI hoặc gửi duyệt lại.',
        $article->workflow_status === 'scheduled' => 'Bài đã có lịch đăng. Xem ở tab Xuất bản.',
        $article->workflow_status === 'published' => 'Bài đã đăng. Xem liên kết ở tab Xuất bản.',
        default => 'Bài đã có bản nháp. Mở ở tab Nội dung để đọc rồi bấm Gửi duyệt khi sẵn sàng.',
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
        .aw-next{border-left:3px solid currentColor;padding:.5rem .75rem;font-size:.9rem;margin:0}
        .aw-grid{display:grid;gap:1rem;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));align-items:start}
        .aw-stack{display:grid;gap:1rem}
        .aw-table{width:100%;border-collapse:collapse;font-size:.875rem}
        .aw-table th,.aw-table td{text-align:left;padding:.45rem .6rem;border-bottom:1px solid var(--ss-border,rgba(128,128,128,.25));vertical-align:top}
        .aw-table th{font-weight:600;opacity:.8;white-space:nowrap}
        .aw-table tr.is-current td{font-weight:600}
        .aw-scroll{overflow-x:auto}
        .aw-muted{opacity:.75;font-size:.85rem}
        .aw-list{margin:0;padding-left:1.1rem;list-style:disc;display:grid;gap:.25rem}
        .aw-link{text-decoration:underline}
        .aw-btn{display:inline-block;font-size:.8rem;font-weight:600;padding:.25rem .8rem;border-radius:.5rem;border:1px solid currentColor;white-space:nowrap}
        .aw-split{display:grid;gap:1rem;grid-template-columns:minmax(0,2fr) minmax(260px,1fr);align-items:start}
        @media (max-width:1024px){.aw-split{grid-template-columns:minmax(0,1fr)}}
        .aw-note{border-left:3px solid currentColor;padding:.4rem .7rem;font-size:.875rem;margin:.6rem 0 0}
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
            @if ($needsExpert)<x-filament::badge color="warning" data-testid="aw-expert">{{ ArticleResource::EXPERT_LABEL }}</x-filament::badge>@endif
            @if ($live)
                <span class="ss-live-pill" data-testid="aw-live-pill"><span class="ss-spin" aria-hidden="true"></span>Đội AI đang chạy · {{ W::runStages()[$liveStage] ?? 'sắp xong' }}</span>
            @endif
            <span class="aw-chip">Từ khóa: {{ $article->primary_keyword }}</span>
            @if ($article->website)<span class="aw-chip">{{ $article->website->name }}</span>@endif
            @if ($campaign)
                <a class="aw-chip" href="{{ CampaignResource::getUrl('edit', ['record' => $campaign]) }}">Chiến dịch: {{ $campaign->name }}</a>
            @endif
        </div>

        <x-studio.tabs :persist="$showPreflight ? null : 'bai-viet-v4-'.$article->id" :default="$tabDefault" :tabs="$tabs">
            {{-- Tổng quan: bài đang ở đâu trong chuỗi, việc tiếp theo, và sửa thông tin bài ngay tại đây. --}}
            <x-studio.panel key="overview">
                <div class="aw-stack">
                    <p class="aw-next" data-testid="aw-next"><strong>Việc tiếp theo:</strong> {{ $next }}</p>
                    @if ($showPreflight)
                        <section class="ss-review-card" data-testid="aw-preflight">
                            <h2 class="ss-review-card__title">AI sẽ tự chuẩn bị</h2>
                            @if (! empty($readiness['auto_actions']))
                                <ul class="aw-list" data-testid="pf-auto">
                                    @foreach ($readiness['auto_actions'] as $act)<li>{{ $act }}</li>@endforeach
                                </ul>
                            @endif
                            <dl class="ss-review-dl">
                                <dt>Rủi ro chủ đề</dt>
                                <dd data-testid="pf-risk"><strong>{{ $riskLabel }}</strong>@if (! empty($readiness['risk']['reasons'])) <span class="aw-muted">· {{ implode(' ', array_slice($readiness['risk']['reasons'], 0, 2)) }}</span>@endif</dd>
                                <dt>Kho kiến thức</dt>
                                <dd data-testid="pf-kb">
                                    @if ($readiness['knowledge']['count'] > 0)
                                        Kho có {{ $readiness['knowledge']['count'] }} mục kiến thức đã duyệt khớp chủ đề:
                                        {{ implode('; ', array_map(fn ($i) => $i['title'], array_slice($readiness['knowledge']['items'], 0, 3))) }}
                                    @else
                                        Kho có 0 mục kiến thức đã duyệt khớp chủ đề.
                                    @endif
                                </dd>
                                <dt>Sản phẩm liên quan</dt><dd data-testid="pf-products">{{ $readiness['products']['count'] }}</dd>
                                <dt>Hồ sơ doanh nghiệp</dt>
                                <dd data-testid="pf-brand">{{ $readiness['brand']['percent'] }}%@if ($readiness['brand']['missing'] !== []) <span class="aw-muted">· thiếu: {{ implode(', ', $readiness['brand']['missing']) }}</span>@endif</dd>
                                <dt>Trí tuệ đang gắn</dt>
                                <dd data-testid="pf-brains">{{ $readiness['brains']['attached'] === [] ? 'Chưa gắn Trí tuệ nào' : implode(', ', array_column($readiness['brains']['attached'], 'name')) }}</dd>
                                <dt>Chi phí ước lượng</dt>
                                <dd data-testid="pf-cost">
                                    {{ $readiness['cost']['estimate_vnd'] === null ? 'Chưa có số liệu để ước lượng' : 'Khoảng '.$money($readiness['cost']['estimate_vnd']) }}
                                    <span class="aw-muted">· ngân sách phiên tối đa {{ $money($readiness['cost']['budget_vnd']) }}</span>
                                </dd>
                            </dl>
                            @if ($readiness['brains']['suggested'] !== [])
                                <div data-testid="pf-suggested" style="margin-top:.6rem">
                                    @foreach ($readiness['brains']['suggested'] as $sg)
                                        <p class="aw-muted">Trí tuệ sẽ tự gắn khi giao: «{{ $sg['name'] }}» · {{ $sg['reason'] }}</p>
                                    @endforeach
                                </div>
                            @endif
                            @if (! empty($autoAttached))
                                <div data-testid="pf-auto-attached" style="margin-top:.6rem">
                                    @foreach ($autoAttached as $at)
                                        <p class="aw-muted" data-testid="pf-auto-row">Đã tự gắn «{{ $at['name'] }}» lúc {{ \Illuminate\Support\Carbon::parse($at['at'])->timezone('Asia/Ho_Chi_Minh')->format('d/m H:i') }}
                                            @if ($canUndoAttach && $article->website) · <button type="button" class="aw-link" data-testid="pf-undo" wire:click="mountAction('undoAutoAttach', @js(['brain' => $at['brain_id']]))">Hoàn tác</button>@endif
                                        </p>
                                    @endforeach
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
                            @if (! empty($readiness['needs_expert']))
                                <p class="aw-note" data-testid="pf-expert" style="color:#b45309">Chủ đề này cần người có chuyên môn: sau khi viết sẽ chuyển cho người có chuyên môn xem lại.</p>
                            @endif
                        </section>
                    @endif
                    <section class="ss-review-card" data-testid="aw-chain">
                        <h2 class="ss-review-card__title">Bài này được làm ra thế nào</h2>
                        <p class="aw-muted" style="margin-bottom:.6rem">Mỗi bước dùng kết quả của bước trước. Bấm vào một bước để xem chi tiết.</p>
                        <div class="aw-chain">
                            @foreach ($chain as $i => $n)
                                @php $st = $liveLink === $n['key'] ? 'run' : $n['state']; @endphp
                                <button type="button" class="aw-node aw-node--{{ $st }} @if ($st === 'run') ss-stage-live @endif" data-link="{{ $n['key'] }}" data-state="{{ $st }}" x-on:click="set(@js($n['tab']))">
                                    <div class="aw-node__k">Bước {{ $i + 1 }} · {{ $stateLabel[$st] }}</div>
                                    <div class="aw-node__t">@if ($st === 'run')<span class="ss-spin" aria-hidden="true"></span>@endif{{ $n['label'] }}</div>
                                    <div class="aw-node__d">{{ $n['text'] }}</div>
                                </button>
                            @endforeach
                        </div>
                    </section>
                    <div class="aw-split">
                        <div data-testid="aw-info-form">
                            <x-filament-panels::form wire:submit="save">
                                {{ $this->form }}
                                <x-filament-panels::form.actions :actions="$this->getCachedFormActions()" :full-width="$this->hasFullWidthFormActions()" />
                            </x-filament-panels::form>
                        </div>
                        <section class="ss-review-card">
                            <h2 class="ss-review-card__title">Số liệu của bài</h2>
                            <dl class="ss-review-dl">
                                <dt>Trạng thái</dt><dd>{{ $statusLabel }}</dd>
                                <dt>Số phiên bản</dt><dd>{{ $versions->count() }}</dd>
                                <dt>Số phiên AI chạy</dt><dd>{{ $runs->count() }}</dd>
                                <dt>Token</dt><dd>{{ $num($totalTokens) }}</dd>
                                <dt>Chi phí AI</dt><dd>{{ V::formatVnd($totalCost) }}</dd>
                                <dt>Ngày tạo</dt><dd>{{ VnTime::format($article->created_at) }}</dd>
                                <dt>Cập nhật</dt><dd>{{ VnTime::format($article->updated_at) }}</dd>
                            </dl>
                        </section>
                    </div>
                </div>
            </x-studio.panel>

            {{-- Từ khóa và kế hoạch: bài sinh ra từ đâu, vì sao đáng viết, nằm cạnh những bài nào. --}}
            <x-studio.panel key="keyword">
                <div class="aw-stack">
                    <div class="aw-grid">
                        <section class="ss-review-card" data-testid="aw-keyword">
                            <div class="aw-head">
                                <h2 class="ss-review-card__title">Từ khóa chính: {{ $article->primary_keyword }}</h2>
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
                                @if ($keywordMatched)
                                    <p class="aw-note">Bài chưa được gắn trực tiếp với từ khóa này; số liệu trên được dò theo tên trong kho từ khóa.</p>
                                @endif
                                @if ($keyword->volume === null)
                                    <p class="aw-note">Từ khóa chưa có số liệu tìm kiếm, nên chưa biết mức độ đáng viết. Nhập số liệu ở kho từ khóa (CSV hoặc Search Console).</p>
                                @endif
                            @else
                                <p>Từ khóa này chưa có trong kho từ khóa, nên chưa có lượt tìm, độ khó hay cụm. Bài được tạo mà không qua bước nghiên cứu từ khóa.</p>
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
                            <h2 class="ss-review-card__title">Cụm “{{ $cluster->name }}”</h2>
                            <p class="aw-muted" style="margin-bottom:.5rem">Các từ khóa cùng cụm nên được phủ bằng các bài liên kết nhau. Từ nào chưa có bài là chỗ còn trống.</p>
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
                        <p class="aw-note" data-testid="aw-gap" style="color:#b45309">{{ ArticleResource::GAP_NOTE }}</p>
                    @endif
                    <div class="aw-grid">
                        <section class="ss-review-card" data-testid="aw-knowledge">
                            <h2 class="ss-review-card__title">Từ Kho trí tuệ</h2>
                            @if ($brains === [])
                                <p>Bài này chưa gắn Trí tuệ nào (theo chiến dịch hoặc website).</p>
                            @else
                                <p class="aw-muted">Trí tuệ đang dùng:
                                    @foreach ($brains as $b)
                                        <a class="aw-link" href="{{ BrainPage::getUrl(['brain' => $b->id]) }}">{{ $b->name }}</a>@if (! $loop->last), @endif
                                    @endforeach
                                </p>
                            @endif
                            @if ($usages->isEmpty())
                                <p style="margin-top:.5rem">{{ $latest ? 'Bản mới nhất không dùng mục kiến thức nào từ kho.' : 'Chưa có bản nào nên chưa dùng kiến thức.' }}</p>
                            @else
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
                        <section class="ss-review-card" data-testid="aw-products">
                            <h2 class="ss-review-card__title">Sản phẩm liên quan của công ty</h2>
                            @if ($products->isEmpty())
                                <p>Chưa có sản phẩm nào trong kho. Thêm mục loại Sản phẩm (hoặc dán trang sản phẩm) ở Kho trí tuệ để bài nhắc đúng sản phẩm.</p>
                                @if (BrainLibraryPage::canAccess())<p><a class="aw-link" href="{{ BrainLibraryPage::getUrl() }}">Mở Kho trí tuệ</a></p>@endif
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
                                <p>Website này chưa có hồ sơ doanh nghiệp, nên AI viết mà không có giọng thương hiệu, thông điệp chính hay danh sách từ cấm.</p>
                            @endif
                        </section>
                        <section class="ss-review-card" data-testid="aw-sources">
                            <h2 class="ss-review-card__title">Nguồn AI nêu trong bài</h2>
                            @if ($sources === [])
                                <p>Chưa có nguồn trích.</p>
                            @else
                                <ul class="aw-list">
                                    @foreach ($sources as $source)
                                        <li>{{ is_array($source) ? implode(' — ', array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $source)) : (string) $source }}</li>
                                    @endforeach
                                </ul>
                                <p class="aw-note">Các nguồn này do AI tự nêu, hệ thống chưa kiểm chứng. Người duyệt cần kiểm trước khi đăng.</p>
                            @endif
                            @php
                                $citable = array_values(array_filter((array) ($research['citable_sources'] ?? [])));
                            @endphp
                            @if ($citable !== [])
                                <p style="margin-top:.5rem">Nguồn được phép trích: {{ count($citable) }} ({{ implode(', ', array_map(fn ($c) => is_array($c) ? (string) ($c['title'] ?? $c['name'] ?? $c['url'] ?? '') : (string) $c, array_slice($citable, 0, 6))) }})</p>
                            @endif
                        </section>
                        <section class="ss-review-card" data-testid="aw-outline">
                            <h2 class="ss-review-card__title">Dàn ý AI lập</h2>
                            @if ($outline === [])
                                <p>Chưa có dàn ý (bài chưa qua bước lập dàn ý của đội AI).</p>
                            @else
                                <ol class="aw-list" style="list-style:decimal">@foreach ($outline as $o)<li>{{ $o }}</li>@endforeach</ol>
                            @endif
                            @if ($links !== [])
                                <p style="margin-top:.6rem"><strong>Liên kết nội bộ đã chèn:</strong> {{ count($links) }}</p>
                            @endif
                        </section>
                    </div>
                    <p class="aw-muted">Hệ thống hiện chưa tự tìm nguồn trên web hay từ tổ chức chuyên ngành; nguồn duy nhất được kiểm soát là Kho trí tuệ và hồ sơ doanh nghiệp.</p>
                </div>
            </x-studio.panel>

            {{-- Nội dung: danh sách phiên bản; bấm Xem để sang trang chi tiết phiên bản (đọc, so sánh, kiểm chất lượng, duyệt). --}}
            <x-studio.panel key="content">
                <div class="aw-stack">
                    <section class="ss-review-card">
                        <h2 class="ss-review-card__title">Các phiên bản</h2>
                        @if ($latest)
                            <p class="aw-muted" data-testid="aw-content-score-note">Điểm hình thức ({{ $qCounts['P0'] }} lỗi P0 · {{ $qCounts['P1'] }} P1 · {{ $qCounts['P2'] }} P2): {{ ArticleResource::SCORE_NOTE }}</p>
                            @if ($noGrounding)<p class="aw-note" data-testid="aw-content-gap" style="color:#b45309">{{ ArticleResource::GAP_NOTE }}</p>@endif
                        @endif
                        @if ($versions->isEmpty())
                            <p>Bài chưa có nội dung. Bấm <strong>Giao đội AI</strong> ở đầu trang để tạo bản nháp đầu tiên.</p>
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
                            <p class="aw-muted" style="margin-top:.5rem">Bấm Xem để đọc toàn bộ nội dung, thẻ SEO, kiểm chất lượng và so sánh với bản trước. Các nút duyệt ở đầu trang áp dụng cho bản mới nhất.</p>
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
                                        @if ($code === $liveStage)<span class="ss-spin" aria-hidden="true"></span>@elseif ($done)✓@endif {{ $label }}
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
                            @else
                                <p class="aw-muted">Phiên vừa bắt đầu, chưa có bước nào xong.</p>
                            @endif
                        </section>
                    @endif
                    <section class="ss-review-card">
                        <h2 class="ss-review-card__title">Các phiên chạy của đội AI</h2>
                        @if ($runs->isEmpty())
                            <p>Bài này chưa được giao cho đội AI.</p>
                        @else
                            <p class="aw-muted" data-testid="aw-score-note" style="margin-bottom:.4rem">Điểm hình thức: {{ ArticleResource::SCORE_NOTE }}</p>
                            @if ($noGrounding)<p class="aw-note" data-testid="aw-score-gap" style="color:#b45309">{{ ArticleResource::GAP_NOTE }}</p>@endif
                            <div class="aw-scroll">
                                <table class="aw-table" data-testid="aw-runs">
                                    <thead><tr><th>Phiên</th><th>Kết quả</th><th title="{{ ArticleResource::SCORE_NOTE }}">Điểm hình thức</th><th>Tạo ra bản</th><th>AI</th><th>Vòng sửa</th><th>Chi phí</th><th>Bắt đầu</th><th></th></tr></thead>
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
                                                <td>{{ ArticleRows::score($r) ?? '—' }}</td>
                                                <td>{{ $fv ? 'v'.$fv->version : '—' }}</td>
                                                <td>{{ $r->ai_mode === 'real' ? 'AI thật' : 'AI giả lập' }}</td>
                                                <td>{{ (int) $r->repair_rounds }}</td>
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
                        <h2 class="ss-review-card__title">Lịch xuất bản của bài</h2>
                        <span class="aw-muted">
                            @if (WeeklySchedule::canAccess())<a class="aw-link" href="{{ WeeklySchedule::getUrl() }}">Lịch tuần</a>@endif
                            @if (PublicationResource::canViewAny()) · <a class="aw-link" href="{{ PublicationResource::getUrl() }}">Tất cả lịch xuất bản</a>@endif
                        </span>
                    </div>
                    @if ($publications->isEmpty())
                        <p>{{ $article->workflow_status === 'approved' ? 'Bài đã duyệt nhưng chưa có lịch. Bấm Xếp lịch ở đầu trang.' : 'Bài chưa có lịch xuất bản. Bài cần được duyệt trước khi xếp lịch.' }}</p>
                    @else
                        <div class="aw-scroll">
                            <table class="aw-table" data-testid="aw-publications">
                                <thead><tr><th>Bản</th><th>Website</th><th>Trạng thái</th><th>Lịch (giờ VN)</th><th>Đã đăng lúc</th><th>Liên kết</th><th>Lỗi gần nhất</th></tr></thead>
                                <tbody>
                                    @foreach ($publications as $p)
                                        <tr>
                                            <td>v{{ $p->articleVersion?->version }}</td>
                                            <td>{{ $dash($p->website?->name) }}</td>
                                            <td>{{ StatusPresenter::label($p->status, 'publication') }}</td>
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
