<div wire:poll.5s>
    @if ($n > 0)
        <a href="{{ $url }}" class="ss-live-pill" data-testid="agent-running-pill" title="Đội AI đang chạy {{ $n }} phiên. Bấm để xem.">
            <span class="ss-spin" aria-hidden="true"></span>
            <span>Đội AI đang chạy ({{ $n }})</span>
        </a>
    @endif
</div>
