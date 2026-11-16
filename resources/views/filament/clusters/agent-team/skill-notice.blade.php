<div data-testid="skill-notice" class="text-sm space-y-2">
    @if ($html !== '')
        <div class="skill-md">{!! $html !!}</div>
    @else
        <div style="opacity:.7">Không đọc được resources/playbooks/NOTICE.md.</div>
    @endif
</div>
