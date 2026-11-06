@php
    use App\Filament\Resources\ArticleVersionResource as R;
    use App\Filament\Support\Diff\ParagraphDiff;
    use App\Filament\Support\StatusPresenter;
    use App\Models\ArticleVersion;

    /** @var ArticleVersion $record */
    $record = $getRecord();
    $article = $record->article;
    $statusLabel = StatusPresenter::label($record->review_status, 'article');
    $statusColor = StatusPresenter::color($record->review_status, 'article');
    $user = auth()->user();
    $canApprove = (bool) ($user && $user->is_active && $user->isApprover());

    // Bản đã duyệt gần nhất trước bản đang xem (cùng bài).
    $prev = ArticleVersion::where('article_id', $record->article_id)
        ->where('version', '<', $record->version)
        ->where('review_status', 'approved')
        ->orderByDesc('version')->first();
    $diff = $prev ? ParagraphDiff::compare((string) $prev->html_content, (string) $record->html_content) : [];
    $changes = array_values(array_filter($diff, fn ($d) => $d['type'] !== 'equal'));
    $nAdded = count(array_filter($changes, fn ($d) => $d['type'] === 'added'));
    $nRemoved = count(array_filter($changes, fn ($d) => $d['type'] === 'removed'));
    $nChanged = count(array_filter($changes, fn ($d) => $d['type'] === 'changed'));
    $numberChanges = [];
    foreach ($changes as $d) {
        foreach ($d['numberChanges'] ?? [] as $n) {
            $numberChanges[] = $n;
        }
    }

    $asset = $record->mediaAssets()->orderByPivot('is_featured', 'desc')->orderByPivot('position')->first();
    $featuredAlt = $asset ? ($asset->pivot->custom_alt ?: $asset->alt_text) : null;

    $next = match (true) {
        $record->review_status === 'needs_review' && $canApprove => 'Đọc bài và phần thay đổi, rồi bấm Duyệt hoặc Từ chối ở đầu trang.',
        $record->review_status === 'needs_review' => 'Bản này đang chờ người có quyền duyệt xử lý.',
        $record->review_status === 'approved' && $article?->workflow_status === 'approved' && $canApprove => 'Bài đã duyệt nhưng chưa có lịch. Bấm Xếp lịch ở đầu trang để chọn thời điểm xuất bản.',
        $record->review_status === 'approved' => 'Bản đã duyệt. Trạng thái bài: ' . StatusPresenter::label($article?->workflow_status, 'article') . '.',
        $record->review_status === 'rejected' => 'Bản này cần sửa. Chỉnh bản thảo rồi gửi duyệt lại.',
        default => 'Chưa có bước tiếp theo cho trạng thái này.',
    };
    $dash = fn ($v) => filled($v) ? $v : '—';
@endphp
<div class="ss-review">
    <div class="ss-review__side">
        <section class="ss-review-card" aria-labelledby="ss-review-decide">
            <h2 class="ss-review-card__title" id="ss-review-decide">Quyết định</h2>
            <p class="ss-review-row"><span class="ss-review-badge ss-review-badge--{{ $statusColor }}">{{ $statusLabel }}</span></p>
            <p class="ss-review-next"><strong>Bước tiếp theo:</strong> {{ $next }}</p>
            <p class="ss-review-muted">Duyệt và Xếp lịch là hai bước riêng; duyệt xong bài vẫn chưa được đăng.</p>
        </section>

        <section class="ss-review-card" aria-labelledby="ss-review-quality">
            <h2 class="ss-review-card__title" id="ss-review-quality">Kiểm chất lượng</h2>
            <p>Chưa có kiểm tra chất lượng tự động (task 3.5).</p>
            <p class="ss-review-muted">Dưới đây chỉ là kiểm tra có/không trên dữ liệu thật, không phải điểm chất lượng.</p>
            <ul class="ss-review-list">
                <li>Meta description: {{ filled($record->meta_description) ? 'đã có' : 'chưa có' }}</li>
                <li>Từ khóa chính của bản: {{ filled($record->focus_keyword) ? 'đã có' : 'chưa có' }}</li>
                <li>Ảnh đại diện: {{ $asset ? (filled($featuredAlt) ? 'có, đã có alt' : 'có, chưa có alt') : 'chưa có ảnh' }}</li>
            </ul>
        </section>

        <section class="ss-review-card" aria-labelledby="ss-review-seo">
            <h2 class="ss-review-card__title" id="ss-review-seo">Thẻ SEO</h2>
            <dl class="ss-review-dl">
                <dt>Tiêu đề</dt><dd>{{ $dash($record->title) }}</dd>
                <dt>Slug</dt><dd>{{ $dash($record->slug) }}</dd>
                <dt>SEO title</dt><dd>{{ $dash($record->seo_title) }}</dd>
                <dt>Meta description</dt><dd>{{ $dash($record->meta_description) }}</dd>
                <dt>Từ khóa chính</dt><dd>{{ $dash($record->focus_keyword) }}</dd>
            </dl>
        </section>

        <section class="ss-review-card" aria-labelledby="ss-review-ai">
            <h2 class="ss-review-card__title" id="ss-review-ai">AI đã tạo</h2>
            <p class="ss-review-muted"><strong>AI đề xuất</strong>, cần người kiểm chứng.</p>
            <dl class="ss-review-dl">
                <dt>Nhà cung cấp</dt><dd>{{ $dash($record->ai_provider) }}</dd>
                <dt>Model</dt><dd>{{ $dash($record->ai_model) }}</dd>
                <dt>Phiên bản prompt</dt><dd>{{ $dash($record->prompt_version) }}</dd>
                <dt>Token</dt><dd>{{ number_format((int) $record->tokens_used, 0, ',', '.') }}</dd>
                <dt>Chi phí</dt><dd>{{ R::formatVnd($record->cost_vnd) }}</dd>
            </dl>
            <h3 class="ss-review-sub">Nguồn trích</h3>
            @include('filament.article-version.sources')
        </section>

        <section class="ss-review-card" aria-labelledby="ss-review-hist">
            <h2 class="ss-review-card__title" id="ss-review-hist">Duyệt &amp; lịch sử</h2>
            <dl class="ss-review-dl">
                <dt>Trạng thái</dt><dd>{{ $statusLabel }}</dd>
                <dt>Người duyệt</dt><dd>{{ $dash($record->reviewer?->name) }}</dd>
                <dt>Thời điểm</dt><dd>{{ $record->reviewed_at?->format('d/m/Y H:i') ?? '—' }}</dd>
                <dt>Ghi chú</dt><dd>{{ $dash($record->review_notes) }}</dd>
            </dl>
            <h3 class="ss-review-sub">Các phiên bản của bài</h3>
            @include('filament.article-version.history')
            <p class="ss-review-muted">Nhật ký chi tiết sẽ có khi Dev1 thêm bảng audit_logs.</p>
        </section>
    </div>

    <div class="ss-review__main">
        <header class="ss-review-head">
            <h2 class="ss-review-head__title">{{ $dash($record->title) }}</h2>
            <p class="ss-review-facts">
                <span>Từ khóa chính: {{ $dash($article?->primary_keyword) }}</span>
                <span>Website đích: {{ $dash($article?->website?->name) }}</span>
                <span>Phiên bản v{{ $record->version }}</span>
                <span class="ss-review-badge ss-review-badge--{{ $statusColor }}">{{ $statusLabel }}</span>
            </p>
        </header>

        <section class="ss-review-card" aria-labelledby="ss-review-body">
            <h2 class="ss-review-card__title" id="ss-review-body">Nội dung bài</h2>
            @include('filament.article-version.content')
        </section>

        <section class="ss-review-card" aria-labelledby="ss-review-media">
            <h2 class="ss-review-card__title" id="ss-review-media">Ảnh</h2>
            @include('filament.article-version.media')
        </section>

        <section class="ss-review-card" aria-labelledby="ss-review-diff">
            <h2 class="ss-review-card__title" id="ss-review-diff">Thay đổi so với bản đã duyệt gần nhất</h2>
            @if (! $prev)
                <p>Chưa có bản đã duyệt để so sánh.</p>
            @else
                <p class="ss-review-muted">So với v{{ $prev->version }} (đã duyệt).
                    <strong>Thêm {{ $nAdded }}</strong>, <strong>xóa {{ $nRemoved }}</strong>, <strong>sửa {{ $nChanged }}</strong>.</p>
                @if ($numberChanges)
                    <div class="ss-review-numbers">
                        <strong>Thay đổi con số, cần đối chiếu nguồn:</strong>
                        <ul class="ss-review-list">
                            @foreach ($numberChanges as $n)
                                <li><span class="ss-review-num">{{ $n['old'] }} → {{ $n['new'] }}</span></li>
                            @endforeach
                        </ul>
                    </div>
                @endif
                @if ($changes)
                    <details class="ss-review-diff" open>
                        <summary>Chi tiết {{ count($changes) }} khối thay đổi</summary>
                        {!! ParagraphDiff::renderHtml($changes) !!}
                    </details>
                @else
                    <p>Không có thay đổi nội dung so với bản đã duyệt.</p>
                @endif
            @endif
        </section>
    </div>
</div>
