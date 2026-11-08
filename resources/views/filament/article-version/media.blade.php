@php($asset = $getRecord()->mediaAssets()->orderByPivot('is_featured', 'desc')->orderByPivot('position')->first())
@if ($asset)
    <figure>
        <img src="{{ $asset->thumbnail_url }}" alt="{{ $asset->pivot->custom_alt ?: $asset->alt_text }}" style="max-width:320px;border-radius:8px;">
        <figcaption>Alt: {{ $asset->pivot->custom_alt ?: ($asset->alt_text ?: '—') }}</figcaption>
    </figure>
@else
    <p>Chưa có ảnh.</p>
@endif
@php($ctxStep = \App\Models\AgentRunStep::query()->whereIn('agent_run_id', \App\Models\AgentRun::where('final_version_id', $getRecord()->id)->select('id'))->whereIn('stage', ['write', 'repair'])->orderByDesc('seq')->first())
@if ($ctxStep && (isset($ctxStep->data['images']) || isset($ctxStep->data['internal_links'])))
    @include('filament.agent.context-assets', ['data' => $ctxStep->data])
@endif
