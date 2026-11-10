@php
    use App\Filament\Resources\AgentRunResource;
    use App\Filament\Resources\ArticleVersionResource as V;
    use App\Filament\Resources\CampaignResource;
    use App\Filament\Support\Review\ArticleRows;
    use App\Filament\Support\Schedule\VnTime;
    use App\Filament\Support\StatusPresenter;

    $dash = fn ($v) => filled($v) ? $v : '—';
    $intents = ['informational' => 'Thông tin', 'commercial' => 'Thương mại', 'transactional' => 'Giao dịch', 'navigational' => 'Điều hướng'];
    $statusLabel = StatusPresenter::label($article->workflow_status, 'article');
    $statusColor = StatusPresenter::color($article->workflow_status, 'article');
    $qCounts = $quality?->counts();
    $lastRun = $runs->first();
    $pubNext = $publications->first();
    $canApprove = \App\Filament\Support\Review\ReviewActions::canApprove();

    // Một câu "việc tiếp theo" theo trạng thái, để người mới vào biết phải làm gì.
    $next = match (true) {
        $live !== null => 'Đội AI đang làm bài này. Theo dõi ở tab Đội AI; trang tự làm mới.',
        $version === null => 'Bài chưa có nội dung. Bấm Giao đội AI ở đầu trang để tạo bản nháp đầu tiên.',
        $article->workflow_status === 'needs_review' && $canApprove => 'Đọc nội dung và phần kiểm chất lượng, rồi bấm Duyệt hoặc Từ chối ở đầu trang.',
        $article->workflow_status === 'needs_review' => 'Bài đang chờ người có quyền duyệt.',
        $article->workflow_status === 'approved' => 'Bài đã duyệt nhưng chưa có lịch. Bấm Xếp lịch ở đầu trang.',
        $article->workflow_status === 'rejected' => 'Bài cần sửa. Xem lý do ở tab Phiên bản, rồi giao lại đội AI hoặc gửi duyệt lại.',
        $article->workflow_status === 'scheduled' => 'Bài đã có lịch đăng. Xem ở tab Xuất bản.',
        $article->workflow_status === 'published' => 'Bài đã đăng. Xem liên kết ở tab Xuất bản.',
        default => 'Bài đã có bản nháp. Xem nội dung rồi bấm Gửi duyệt khi sẵn sàng.',
    };

    $qTone = $quality === null ? null : ($quality->hasBlocking() ? 'danger' : (($qCounts['P1'] ?? 0) > 0 ? 'warn' : null));
    $tabs = [
        ['key' => 'overview', 'label' => 'Tổng quan'],
        ['key' => 'content', 'label' => 'Nội dung'],
        ['key' => 'seo', 'label' => 'SEO và chất lượng', 'tone' => $qTone],
        ['key' => 'versions', 'label' => 'Phiên bản', 'count' => $versions->count()],
        ['key' => 'runs', 'label' => 'Đội AI', 'count' => $runs->count(), 'tone' => $live ? 'info' : null],
        ['key' => 'brain', 'label' => 'Trí tuệ và nguồn'],
        ['key' => 'media', 'label' => 'Ảnh'],
        ['key' => 'publish', 'label' => 'Xuất bản', 'count' => $publications->count() ?: null],
    ];
    $get = fn () => $version; // các partial của màn duyệt đọc bản bài qua $getRecord()
@endphp
<x-filament-panels::page>
    <style>
        .aw-meta{display:flex;flex-wrap:wrap;gap:.4rem .5rem;align-items:center;margin-top:-.4rem}
        .aw-chip{font-size:.75rem;padding:.1rem .55rem;border-radius:999px;border:1px solid var(--ss-border,rgba(128,128,128,.35))}
        a.aw-chip{text-decoration:underline}
        .aw-next{border-left:3px solid currentColor;padding:.5rem .75rem;font-size:.9rem;margin:0}
        .aw-next > span{color:var(--ss-text,inherit)}
        .aw-grid{display:grid;gap:1rem;grid-template-columns:repeat(auto-fit,minmax(260px,1fr))}
        .aw-stack{display:grid;gap:1rem}
        .aw-table{width:100%;border-collapse:collapse;font-size:.875rem}
        .aw-table th,.aw-table td{text-align:left;padding:.45rem .6rem;border-bottom:1px solid var(--ss-border,rgba(128,128,128,.25));vertical-align:top}
        .aw-table th{font-weight:600;opacity:.8;white-space:nowrap}
        .aw-scroll{overflow-x:auto}
        .aw-muted{opacity:.75;font-size:.85rem}
        .aw-list{margin:0;padding-left:1.1rem;list-style:disc;display:grid;gap:.25rem}
        .aw-link{text-decoration:underline}
    </style>
    <div class="aw-stack" data-testid="article-workspace" @if ($live) wire:poll.{{ $pollSeconds }}s @endif>
        <div class="aw-meta" data-testid="aw-meta">
            <x-filament::badge :color="$statusColor">{{ $statusLabel }}@if ($live) · {{ $liveStage ?? 'AI' }}@endif</x-filament::badge>
            <span class="aw-chip">Từ khóa: {{ $article->primary_keyword }}</span>
            @if ($article->website)<span class="aw-chip">{{ $article->website->name }}</span>@endif
            @if ($campaign)
                <a class="aw-chip" href="{{ CampaignResource::getUrl('edit', ['record' => $campaign]) }}">Chiến dịch: {{ $campaign->name }}</a>
            @endif
            @if ($version)<span class="aw-chip">Bản mới nhất v{{ $version->version }}</span>@endif
        </div>

        <p class="aw-next" data-testid="aw-next"><span><strong>Việc tiếp theo:</strong> {{ $next }}</span></p>

        <x-studio.tabs persist="bai-viet" :tabs="$tabs">
            <x-studio.panel key="overview">
                <div class="aw-grid">
                    <section class="ss-review-card">
                        <h2 class="ss-review-card__title">Thông tin bài</h2>
                        <dl class="ss-review-dl">
                            <dt>Từ khóa chính</dt><dd>{{ $article->primary_keyword }}</dd>
                            <dt>Từ khóa phụ</dt><dd>{{ $dash(implode(', ', (array) $article->secondary_keywords)) }}</dd>
                            <dt>Ý định tìm kiếm</dt><dd>{{ $intents[$article->search_intent] ?? $dash($article->search_intent) }}</dd>
                            <dt>Cụm chủ đề</dt><dd>{{ $dash($article->topic_cluster) }}</dd>
                            <dt>Website đích</dt><dd>{{ $dash($article->website?->name) }}</dd>
                            <dt>Chiến dịch</dt><dd>{{ $dash($campaign?->name) }}</dd>
                            <dt>Ngày tạo</dt><dd>{{ VnTime::format($article->created_at) }}</dd>
                            <dt>Cập nhật</dt><dd>{{ VnTime::format($article->updated_at) }}</dd>
                        </dl>
                    </section>
                    <section class="ss-review-card">
                        <h2 class="ss-review-card__title">Tình trạng</h2>
                        <dl class="ss-review-dl">
                            <dt>Trạng thái</dt><dd>{{ $statusLabel }}</dd>
                            <dt>Số phiên bản</dt><dd>{{ $versions->count() }}</dd>
                            <dt>Chất lượng</dt>
                            <dd>
                                @if ($quality === null) —
                                @elseif ($quality->hasBlocking()) Còn {{ $qCounts['P0'] }} lỗi P0 chặn duyệt
                                @else Không còn lỗi chặn (P1: {{ $qCounts['P1'] }}, P2: {{ $qCounts['P2'] }})
                                @endif
                            </dd>
                            <dt>Phiên AI gần nhất</dt>
                            <dd>
                                @if ($lastRun)
                                    {{ AgentRunResource::STATUS[$lastRun->status][0] ?? $lastRun->status }}@if (($sc = ArticleRows::score($lastRun)) !== null) · {{ $sc }} điểm @endif
                                @else Chưa giao đội AI
                                @endif
                            </dd>
                            <dt>Lịch đăng</dt><dd>{{ $pubNext ? StatusPresenter::label($pubNext->status, 'publication').' · '.VnTime::format($pubNext->scheduled_at_utc).' (giờ VN)' : 'Chưa có' }}</dd>
                        </dl>
                    </section>
                    <section class="ss-review-card">
                        <h2 class="ss-review-card__title">Chi phí AI của bài</h2>
                        <dl class="ss-review-dl">
                            <dt>Số phiên chạy</dt><dd>{{ $runs->count() }}</dd>
                            <dt>Token</dt><dd>{{ number_format($totalTokens, 0, ',', '.') }}</dd>
                            <dt>Chi phí</dt><dd>{{ V::formatVnd($totalCost) }}</dd>
                        </dl>
                        <p class="aw-muted">Cộng từ các phiên chạy của đội AI cho bài này.</p>
                    </section>
                </div>
            </x-studio.panel>

            <x-studio.panel key="content">
                @if ($version)
                    @include('filament.agent.cms-draft-line', ['version' => $version])
                    <section class="ss-review-card">
                        <h2 class="ss-review-card__title">{{ $dash($version->title) }} <span class="aw-muted">· v{{ $version->version }} · {{ StatusPresenter::label($version->review_status, 'article') }}</span></h2>
                        @include('filament.article-version.content', ['getRecord' => $get])
                        @if ($reviewUrl)
                            <p class="aw-muted" style="margin-top:.6rem"><a class="aw-link" href="{{ $reviewUrl }}">Mở màn duyệt</a> để xem phần thay đổi so với bản đã duyệt trước.</p>
                        @endif
                    </section>
                @else
                    <section class="ss-review-card"><p>Bài chưa có nội dung. Bấm <strong>Giao đội AI</strong> ở đầu trang để tạo bản nháp đầu tiên.</p></section>
                @endif
            </x-studio.panel>

            <x-studio.panel key="seo">
                @if ($version)
                    <div class="aw-grid">
                        <section class="ss-review-card">
                            <h2 class="ss-review-card__title">Thẻ SEO</h2>
                            <dl class="ss-review-dl">
                                <dt>Tiêu đề</dt><dd>{{ $dash($version->title) }}</dd>
                                <dt>Slug</dt><dd>{{ $dash($version->slug) }}</dd>
                                <dt>SEO title</dt><dd>{{ $dash($version->seo_title) }}</dd>
                                <dt>Meta description</dt><dd>{{ $dash($version->meta_description) }}</dd>
                                <dt>Từ khóa chính</dt><dd>{{ $dash($version->focus_keyword) }}</dd>
                            </dl>
                            @if (is_array($version->outline) && $version->outline !== [])
                                <h3 class="ss-review-sub">Dàn ý</h3>
                                <ul class="aw-list">
                                    @foreach ($version->outline as $o)
                                        <li>{{ is_array($o) ? ($o['heading'] ?? $o['title'] ?? implode(' — ', array_filter($o, 'is_scalar'))) : (string) $o }}</li>
                                    @endforeach
                                </ul>
                            @endif
                        </section>
                        <section class="ss-review-card" data-testid="aw-quality">
                            <h2 class="ss-review-card__title">Kiểm chất lượng</h2>
                            <p class="aw-muted">P0 chưa đạt: {{ $qCounts['P0'] }} · P1: {{ $qCounts['P1'] }} · P2: {{ $qCounts['P2'] }}</p>
                            @if ($quality->failed())
                                <ul class="aw-list">
                                    @foreach ($quality->failed() as $r)
                                        <li><strong>{{ $r->severity }} · {{ $r->code }}</strong> {{ $r->label }}: {{ $r->message }}</li>
                                    @endforeach
                                </ul>
                            @else
                                <p>Không có mục nào chưa đạt.</p>
                            @endif
                        </section>
                    </div>
                @else
                    <section class="ss-review-card"><p>Chưa có bản bài nào để kiểm.</p></section>
                @endif
            </x-studio.panel>

            <x-studio.panel key="versions">
                <section class="ss-review-card">
                    @if ($versions->isEmpty())
                        <p>Chưa có phiên bản nào.</p>
                    @else
                        <div class="aw-scroll">
                            <table class="aw-table" data-testid="aw-versions">
                                <thead><tr><th>Bản</th><th>Tiêu đề</th><th>Trạng thái</th><th>Người duyệt</th><th>Ghi chú duyệt</th><th>Tạo lúc</th><th></th></tr></thead>
                                <tbody>
                                    @foreach ($versions as $v)
                                        <tr>
                                            <td>v{{ $v->version }}</td>
                                            <td>{{ $dash($v->title) }}</td>
                                            <td>{{ StatusPresenter::label($v->review_status, 'article') }}</td>
                                            <td>{{ $dash($v->reviewer?->name) }}</td>
                                            <td>{{ $dash($v->review_notes) }}</td>
                                            <td>{{ VnTime::format($v->created_at) }}</td>
                                            <td><a class="aw-link" href="{{ V::getUrl('view', ['record' => $v]) }}">Xem và so sánh</a></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </section>
            </x-studio.panel>

            <x-studio.panel key="runs">
                <section class="ss-review-card">
                    @if ($runs->isEmpty())
                        <p>Bài này chưa được giao cho đội AI.</p>
                    @else
                        <div class="aw-scroll">
                            <table class="aw-table" data-testid="aw-runs">
                                <thead><tr><th>Phiên</th><th>Kết quả</th><th>Điểm</th><th>AI</th><th>Vòng sửa</th><th>Chi phí</th><th>Bắt đầu</th><th></th></tr></thead>
                                <tbody>
                                    @foreach ($runs as $r)
                                        <tr>
                                            <td>#{{ $r->id }}</td>
                                            <td>{{ AgentRunResource::STATUS[$r->status][0] ?? $r->status }}@if ($r->outcome_reason)<div class="aw-muted">{{ \Illuminate\Support\Str::limit($r->outcome_reason, 140) }}</div>@endif</td>
                                            <td>{{ ArticleRows::score($r) ?? '—' }}</td>
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
            </x-studio.panel>

            <x-studio.panel key="brain">
                <div class="aw-grid">
                    <section class="ss-review-card">
                        <h2 class="ss-review-card__title">Trí tuệ đang dùng</h2>
                        @if ($brains === [])
                            <p>Bài này chưa gắn Trí tuệ nào (theo chiến dịch hoặc website).</p>
                        @else
                            <ul class="aw-list">
                                @foreach ($brains as $b)
                                    <li><a class="aw-link" href="{{ \App\Filament\Pages\BrainPage::getUrl(['brain' => $b->id]) }}">{{ $b->name }}</a></li>
                                @endforeach
                            </ul>
                        @endif
                    </section>
                    <section class="ss-review-card">
                        <h2 class="ss-review-card__title">Kiến thức đã dùng trong bản mới nhất</h2>
                        @if ($usages->isEmpty())
                            <p>Bản này không ghi nhận mục kiến thức nào từ kho.</p>
                        @else
                            <ul class="aw-list">
                                @foreach ($usages as $u)
                                    <li>{{ $u->item?->title ?? 'Mục đã bị xóa' }} <span class="aw-muted">(bản {{ $u->item_version }})</span></li>
                                @endforeach
                            </ul>
                        @endif
                    </section>
                    <section class="ss-review-card">
                        <h2 class="ss-review-card__title">Nguồn trích</h2>
                        @if ($version)
                            @include('filament.article-version.sources', ['getRecord' => $get])
                        @else
                            <p>Chưa có nguồn trích.</p>
                        @endif
                    </section>
                </div>
            </x-studio.panel>

            <x-studio.panel key="media">
                <section class="ss-review-card">
                    @if ($version)
                        @include('filament.article-version.media', ['getRecord' => $get])
                    @else
                        <p>Chưa có ảnh.</p>
                    @endif
                </section>
            </x-studio.panel>

            <x-studio.panel key="publish">
                <section class="ss-review-card">
                    @if ($publications->isEmpty())
                        <p>Bài chưa có lịch xuất bản. Bài cần được duyệt trước khi xếp lịch.</p>
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
                                            <td>{{ $dash($p->last_error ? \Illuminate\Support\Str::limit($p->last_error, 120) : null) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                    <p class="aw-muted" style="margin-top:.6rem">Số liệu hiệu quả (lượt hiển thị, lượt bấm) chưa có vì Search Console chưa kết nối.</p>
                </section>
            </x-studio.panel>
        </x-studio.tabs>
    </div>
</x-filament-panels::page>
