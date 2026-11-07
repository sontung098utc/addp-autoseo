@php
    /** @var \App\Models\ArticleVersion|null $version */
    $draftUrl = $version?->cms_draft_url;
    $safeUrl = is_string($draftUrl) && preg_match('~^https://~i', $draftUrl) ? $draftUrl : null;
@endphp
@if ($version && $version->cms_draft_status)
    <div class="text-sm" data-testid="cms-draft-line" style="margin:.4rem 0">
        Nháp trên CMS:
        @if ($safeUrl && in_array($version->cms_draft_status, ['pushed', 'promoted'], true))
            <a class="underline" href="{{ $safeUrl }}" target="_blank" rel="noopener noreferrer">link xem trước</a>
        @else
            <span style="opacity:.7">không có link</span>
        @endif
        · {{ \App\Services\Agent\Handoff\CmsDraftHandoff::statusLabel($version->cms_draft_status) }}
    </div>
@endif
