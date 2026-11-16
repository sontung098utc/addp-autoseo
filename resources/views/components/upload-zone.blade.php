@props([
    'model' => null,
    'multiple' => false,
    'accept' => null,
    'hint' => null,
    'maxMb' => null,
    'label' => 'Kéo thả tệp vào đây hoặc bấm để chọn',
    'name' => 'upload',
    'files' => [],
])
{{--
    Khu thả tệp dùng chung (Livewire + Alpine). Sáng/tối, dùng được trên điện thoại.

    Props:
      model     (string)  tên thuộc tính Livewire (wire:model), vd "docFile". Bắt buộc để tải lên.
      multiple  (bool)    cho chọn nhiều tệp. Mặc định false.
      accept    (string)  giá trị thuộc tính accept, vd ".pdf,.docx,.md,.txt".
      hint      (string)  dòng nhỏ định dạng/dung lượng, vd "PDF, DOCX, MD, TXT · tối đa 10 MB". Mặc định tự ghép từ accept/maxMb.
      maxMb     (int)     chỉ để hiển thị trong dòng nhỏ.
      label     (string)  chữ chính.
      name      (string)  tiền tố data-testid ("{name}-zone", "{name}-input", "{name}-list").
      files     (array)   danh sách tệp đã chọn phía máy chủ để hiện dạng hàng: [['name'=>..., 'size'=>bytes, 'remove'=>'wireMethod(args)' (tùy chọn)], ...].
    Slot mặc định: nội dung thêm dưới khu thả (vd nút "Tải lên").
    Tệp vừa chọn (chưa lưu) cũng hiện thành hàng ngay phía trình duyệt, kèm thanh tiến trình (sự kiện livewire-upload-*) và nút X bỏ chọn.
--}}
@once
    <style>
        .uz{display:flex;flex-direction:column;gap:.5rem}
        .uz .uz-drop{opacity:1;margin:0;font-size:inherit;display:flex;flex-direction:column;align-items:center;gap:.25rem;padding:1.1rem .75rem;text-align:center;cursor:pointer;border:1.5px dashed rgba(128,128,128,.5);border-radius:.9rem;background:rgba(128,128,128,.05);transition:border-color .15s,background .15s}
        .uz .uz-drop:hover,.uz .uz-drop:focus-visible{border-color:#6366f1;background:rgba(99,102,241,.07);outline:none}
        .uz .uz-drop.is-over{border-color:#4f46e5;border-style:solid;background:rgba(99,102,241,.14)}
        .uz-ico{width:1.9rem;height:1.9rem;opacity:.7}
        .uz-drop.is-over .uz-ico{opacity:1;color:#4f46e5}
        .uz-t{font-size:.875rem;font-weight:600}
        .uz-h{font-size:.75rem;opacity:.65}
        .uz-list{display:flex;flex-direction:column;gap:.3rem;margin:0;padding:0;list-style:none}
        .uz-row{display:flex;align-items:center;gap:.5rem;padding:.35rem .5rem;border:1px solid rgba(128,128,128,.28);border-radius:.55rem;font-size:.8125rem;position:relative;overflow:hidden}
        .uz-row svg{flex:none;width:1.1rem;height:1.1rem;opacity:.7}
        .uz-n{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .uz-s{flex:none;font-size:.75rem;opacity:.6}
        .uz-x{flex:none;display:inline-flex;align-items:center;justify-content:center;width:1.4rem;height:1.4rem;border:0;border-radius:999px;background:transparent;color:inherit;cursor:pointer;opacity:.6}
        .uz-x:hover{opacity:1;background:rgba(220,38,38,.14);color:#dc2626}
        .uz-x svg{width:.9rem;height:.9rem;opacity:1}
        .uz-bar{position:absolute;left:0;bottom:0;height:3px;background:#6366f1;transition:width .15s}
        @media (max-width:640px){.uz-drop{padding:.9rem .5rem}}
    </style>
@endonce
@php
    $hintText = $hint ?? trim(($accept ? strtoupper(str_replace(['.', ','], ['', ', '], str_replace(' ', '', $accept))) : '').($maxMb ? ' · tối đa '.$maxMb.' MB' : ''), ' ·');
    $fmt = fn ($b) => $b >= 1048576 ? round($b / 1048576, 1).' MB' : max(1, (int) round($b / 1024)).' KB';
@endphp
<div class="uz" data-testid="{{ $name }}-zone"
     x-data="{ over: false, picked: [], progress: 0, busy: false,
        fmt(b){ return b >= 1048576 ? (b/1048576).toFixed(1)+' MB' : Math.max(1, Math.round(b/1024))+' KB' },
        sync(){ this.picked = Array.from($refs.input.files || []) },
        drop(e){ this.over = false; if (!e.dataTransfer?.files?.length) return; $refs.input.files = e.dataTransfer.files; $refs.input.dispatchEvent(new Event('change', { bubbles: true })) },
        remove(i){ const dt = new DataTransfer(); this.picked.forEach((f, k) => { if (k !== i) dt.items.add(f) }); $refs.input.files = dt.files; $refs.input.dispatchEvent(new Event('change', { bubbles: true })); this.sync() } }"
     x-on:livewire-upload-start="busy = true; progress = 0"
     x-on:livewire-upload-progress="progress = $event.detail.progress"
     x-on:livewire-upload-finish="busy = false; progress = 100"
     x-on:livewire-upload-error="busy = false">
    <label class="uz-drop" :class="{ 'is-over': over }" tabindex="0" role="button" aria-label="{{ $label }}"
           @keydown.enter.prevent="$refs.input.click()" @keydown.space.prevent="$refs.input.click()"
           @dragover.prevent="over = true" @dragleave.prevent="over = false" @drop.prevent="drop($event)">
        <x-filament::icon icon="heroicon-o-cloud-arrow-up" class="uz-ico" />
        <span class="uz-t">{{ $label }}</span>
        @if ($hintText !== '')<span class="uz-h">{{ $hintText }}</span>@endif
        <input type="file" x-ref="input" class="sr-only" style="position:absolute;width:1px;height:1px;opacity:0" data-testid="{{ $name }}-input"
               @if ($model) wire:model="{{ $model }}" @endif @if ($multiple) multiple @endif @if ($accept) accept="{{ $accept }}" @endif
               @change="sync()">
    </label>
    @if (count($files))
        <ul class="uz-list" data-testid="{{ $name }}-list">
            @foreach ($files as $f)
                <li class="uz-row">
                    <x-filament::icon icon="heroicon-o-document-text" />
                    <span class="uz-n" title="{{ $f['name'] }}">{{ $f['name'] }}</span>
                    @if (isset($f['size']))<span class="uz-s">{{ $fmt((int) $f['size']) }}</span>@endif
                    @if (! empty($f['remove']))
                        <button type="button" class="uz-x" wire:click="{{ $f['remove'] }}" aria-label="Bỏ tệp" title="Bỏ tệp"><x-filament::icon icon="heroicon-m-x-mark" /></button>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
    <template x-if="picked.length">
        <ul class="uz-list" data-testid="{{ $name }}-picked">
            <template x-for="(f, i) in picked" :key="i + f.name">
                <li class="uz-row">
                    <x-filament::icon icon="heroicon-o-document-arrow-up" />
                    <span class="uz-n" x-text="f.name" :title="f.name"></span>
                    <span class="uz-s" x-text="fmt(f.size)"></span>
                    <button type="button" class="uz-x" @click="remove(i)" aria-label="Bỏ tệp" title="Bỏ tệp"><x-filament::icon icon="heroicon-m-x-mark" /></button>
                    <span class="uz-bar" x-show="busy" :style="'width:' + progress + '%'"></span>
                </li>
            </template>
        </ul>
    </template>
    {{ $slot }}
</div>
