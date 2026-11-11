@php
    $p = $s['profile'];
    $edit = $s['canEdit'];
    $tabs = \App\Filament\Clusters\AgentTeam\Pages\AgentRoles::STUDIO_TABS;
    $btn = 'padding:.35rem .75rem;border-radius:.5rem;border:1px solid rgba(128,128,128,.45);background:transparent;color:inherit;cursor:pointer;font-size:.8rem';
    $btnPrimary = 'padding:.4rem .9rem;border-radius:.5rem;border:1px solid #2563eb;background:#2563eb;color:#fff;cursor:pointer;font-size:.8rem';
    $input = 'width:100%;padding:.4rem .55rem;border-radius:.5rem;border:1px solid rgba(128,128,128,.45);background:transparent;color:inherit;font-size:.85rem';
    $statusTone = ['ok' => 'ok', 'warn' => 'warn', 'ready_for_review' => 'ok', 'failed' => 'danger', 'blocked' => 'danger', 'held' => 'warn'];
@endphp
<style>
    .ags{--ags-bg:#fff;--ags-side:#f3f4f6;position:fixed;inset:0;z-index:30;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,.5);padding:1rem}
    .dark .ags{--ags-bg:#111827;--ags-side:#0b1220}
    .ags-win{background:var(--ags-bg);color:inherit;border-radius:1rem;width:min(1100px,100%);height:min(780px,100%);display:flex;overflow:hidden;box-shadow:0 20px 50px rgba(0,0,0,.35)}
    .ags-side{width:15rem;flex:none;background:var(--ags-side);padding:1rem .6rem;overflow-y:auto;display:flex;flex-direction:column;gap:.1rem}
    .ags-grp{font-size:.62rem;letter-spacing:.08em;opacity:.55;margin:.8rem .5rem .2rem}
    .ags-nav{text-align:left;padding:.45rem .6rem;border-radius:.5rem;background:transparent;border:0;color:inherit;cursor:pointer;font-size:.82rem}
    .ags-nav:hover{background:rgba(128,128,128,.15)}
    .ags-nav[aria-current=true]{background:rgba(37,99,235,.16);font-weight:600}
    .ags-main{flex:1;min-width:0;display:flex;flex-direction:column}
    .ags-head{display:flex;justify-content:space-between;align-items:center;gap:.5rem;padding:.8rem 1.2rem;border-bottom:1px solid rgba(128,128,128,.25)}
    .ags-body{flex:1;overflow-y:auto;padding:1.2rem}
    .ags-body h3{font-size:.95rem;font-weight:600;margin:0 0 .4rem}
    .ags-body label{font-size:.75rem;opacity:.75;display:block;margin:.6rem 0 .2rem}
    .ags-card{border:1px solid rgba(128,128,128,.3);border-radius:.6rem;padding:.6rem .75rem;margin-bottom:.5rem}
    .ags-chip{display:inline-flex;align-items:center;gap:.25rem;padding:.15rem .55rem;border-radius:999px;border:1px solid rgba(128,128,128,.4);font-size:.75rem;background:transparent;color:inherit}
    .ags-chip--on{background:rgba(37,99,235,.15);border-color:#2563eb}
    .ags-flow{display:flex;flex-wrap:wrap;gap:.3rem;align-items:center}
    .ags-step{padding:.2rem .55rem;border-radius:.4rem;font-size:.72rem;border:1px solid rgba(128,128,128,.3);opacity:.4}
    .ags-step--mine{opacity:1;background:#2563eb;border-color:#2563eb;color:#fff;font-weight:600}
    .ags-step--planned{border-style:dashed}
    .ags-ph{display:flex;justify-content:space-between;align-items:flex-start;gap:.8rem;flex-wrap:wrap;margin-bottom:.9rem}
    .ags-ph h3{margin:0}.ags-desc{font-size:.78rem;opacity:.65;margin-top:.1rem}
    .ags-act{display:flex;gap:.4rem;flex-wrap:wrap}
    .ags-sec{font-size:.72rem;letter-spacing:.04em;font-weight:600;opacity:.65;margin:1rem 0 .4rem;text-transform:uppercase}
    .rc-dot{width:.5rem;height:.5rem;border-radius:50%;background:rgb(14,165,233);animation:rc-blink 1.2s ease-in-out infinite}
    @keyframes rc-blink{50%{opacity:.25}}
    .ags-bar{height:.5rem;border-radius:999px;background:rgba(128,128,128,.25);overflow:hidden}
    .ags-bar>i{display:block;height:100%;background:#16a34a}
    @media (max-width:760px){.ags-win{flex-direction:column}.ags-side{width:auto;flex-direction:row;overflow-x:auto;padding:.4rem}.ags-grp{display:none}.ags-nav{white-space:nowrap}}
</style>
<div class="ags" data-testid="agent-studio" wire:key="studio-{{ $s['key'] }}" wire:keydown.escape.window="closeStudio">
    <div class="ags-win" role="dialog" aria-label="Agent Studio: {{ $p['display_name'] }}">
        <nav class="ags-side" aria-label="Điều hướng hồ sơ">
            @foreach ($tabs as $k => [$label, $group])
                @if ($group)<div class="ags-grp">{{ $group }}</div>@endif
                <button type="button" class="ags-nav" wire:click="setStudioTab('{{ $k }}')" aria-current="{{ $s['tab'] === $k ? 'true' : 'false' }}" data-testid="studio-tab-{{ $k }}">{{ $label }}</button>
            @endforeach
        </nav>
        <div class="ags-main">
            <div class="ags-head">
                <div style="display:flex;gap:.7rem;align-items:center;min-width:0">
                    @include('filament.clusters.agent-team.agent-studio-avatar', ['avatar' => $p['avatar'], 'size' => 2.6])
                    <div style="min-width:0"><strong data-testid="studio-name">{{ $p['display_name'] }}</strong><div class="text-xs" style="opacity:.7">{{ $p['title'] }}</div></div>
                </div>
                <div style="display:flex;gap:.5rem;align-items:center">
                    @if (! $edit)<x-studio.badge tone="muted">Chỉ xem</x-studio.badge>@endif
                    <button type="button" style="{{ $btn }}" wire:click="closeStudio" data-testid="studio-close">Đóng</button>
                </div>
            </div>
            <div class="ags-body" data-testid="studio-pane-{{ $s['tab'] }}">
            @switch($s['tab'])
                @case('overview')
                    @php $w = $s['week']; $working = $s['working']; @endphp
                    <div class="ags-ph">
                        <div><h3>Tổng quan</h3><div class="ags-desc">{{ $s['role']['goal'] }}</div></div>
                        <div class="ags-act"><button type="button" style="{{ $btn }}" wire:click="mountAction('detail', {{ \Illuminate\Support\Js::from(['role' => $s['key']]) }})">Chi tiết kỹ thuật</button></div>
                    </div>
                    <div class="ags-card" style="display:flex;justify-content:space-between;gap:.6rem;align-items:center;flex-wrap:wrap" data-testid="studio-status">
                        @if ($working)
                            <span data-status="working"><span class="rc-dot" style="display:inline-block;margin-right:.35rem"></span><strong>Đang làm:</strong> {{ $working[0]['current'] ?? $working[0]['job_title'] }} · {{ preg_replace('/^(Bài|Website): /u', '', $working[0]['subject']) }}
                                @if (\App\Filament\Resources\AgentRunResource::canViewAny())<a href="{{ ($s['runUrl'])($working[0]['run']->id) }}" style="text-decoration:underline;margin-left:.4rem" class="text-xs">Xem phiên</a>@endif</span>
                        @else
                            <span data-status="idle" style="opacity:.75">Đang rảnh</span>
                        @endif
                        <x-studio.badge :tone="$s['enabled'] ? 'ok' : 'muted'">{{ $s['enabled'] ? 'Đang bật' : 'Đang tắt' }}</x-studio.badge>
                    </div>
                    <div class="ags-sec">Đã sẵn sàng chưa</div>
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(10rem,1fr));gap:.5rem" data-testid="studio-checks">
                        @foreach ($s['checks'] as $c)
                            <button type="button" class="ags-card ags-check" style="text-align:left;cursor:pointer;color:inherit;background:transparent;margin:0" wire:click="setStudioTab('{{ $c['tab'] }}')" data-ok="{{ $c['ok'] ? 1 : 0 }}">
                                <span style="color:{{ $c['ok'] ? '#16a34a' : 'inherit' }};opacity:{{ $c['ok'] ? 1 : .5 }}">{{ $c['ok'] ? '✓' : '○' }}</span> <strong class="text-sm">{{ $c['label'] }}</strong>
                                <div class="text-xs" style="opacity:.6">{{ $c['note'] ?? ($c['ok'] ? 'Xong' : 'Chưa có') }}</div>
                            </button>
                        @endforeach
                    </div>
                    <div class="ags-sec">7 ngày gần nhất</div>
                    @if ($w['steps'] > 0)
                        <div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:.5rem" data-testid="studio-week">
                            <div class="ags-card"><div class="text-xs" style="opacity:.7">Phiên</div><strong>{{ number_format($w['runs']) }}</strong></div>
                            <div class="ags-card"><div class="text-xs" style="opacity:.7">Tỉ lệ ok</div><strong>{{ $w['ok_rate'] }}%</strong></div>
                            <div class="ags-card"><div class="text-xs" style="opacity:.7">Chi phí</div><strong>{{ number_format($w['cost'], 0, ',', '.') }} ₫</strong></div>
                        </div>
                    @else
                        <div class="text-sm" style="opacity:.65" data-testid="studio-week-empty">Chưa có phiên nào trong 7 ngày qua.</div>
                    @endif
                    <div class="text-xs" style="margin-top:1rem;display:flex;align-items:center;gap:.5rem">
                        <span style="opacity:.75">Đào tạo <strong data-testid="studio-percent">{{ $p['training_percent'] }}%</strong></span>
                        <div class="ags-bar" style="width:8rem"><i style="width:{{ $p['training_percent'] }}%"></i></div>
                        <x-help title="Đào tạo">
                            <p>{{ $p['training_percent'] >= 100 ? 'Đã đủ persona, quy tắc, bài học, kỹ năng.' : 'Còn thiếu: persona, quy tắc, bài học, kỹ năng hoặc tài liệu riêng.' }}</p>
                            <p>Hồ sơ: {{ $p['is_customized'] ? 'đã tùy chỉnh, phiên bản '.$p['version'] : 'mặc định từ tệp vai trò (chưa ai tùy chỉnh, hệ thống chạy như cũ)' }}. Chỉ dẫn từ hồ sơ áp dụng vào prompt từ lần chạy kế tiếp.</p>
                        </x-help>
                    </div>
                    @break

                @case('persona')
                    <div class="ags-ph">
                        <div><h3>Vai trò & chỉ dẫn <x-help title="Chỉ dẫn">
                            <p>Mỗi lần lưu tạo một phiên bản và áp dụng từ lần chạy kế tiếp.</p>
                            <p>Persona và quy tắc đi vào prompt như dữ liệu của quản trị; không ghi đè quy tắc an toàn, bộ luật chất lượng hay định dạng đầu ra. Để trống một ô để quay về mặc định.</p>
                        </x-help></h3><div class="ags-desc">Tên, giọng và quy tắc làm việc của nhân sự này.</div></div>
                        <div class="ags-act">
                            <button type="button" style="{{ $btn }}" wire:click="toggleHistory" data-testid="studio-history-btn">Lịch sử</button>
                            @if ($edit)
                                <button type="button" style="{{ $btn }}" wire:click="undoProfile" @disabled(! $s['canUndo']) data-testid="studio-undo">Hoàn tác</button>
                                <button type="button" style="{{ $btnPrimary }}" wire:click="saveProfile" data-testid="studio-save">Lưu</button>
                            @endif
                        </div>
                    </div>
                    @if ($showHistory)
                        <div class="ags-card" data-testid="studio-history">
                            @forelse ($s['history'] as $h)
                                <div style="display:flex;justify-content:space-between;gap:.5rem;font-size:.8rem;padding:.2rem 0">
                                    <span>v{{ $h['version'] }} · {{ $h['summary'] }} <span style="opacity:.6">· {{ $h['by'] ?? 'hệ thống' }} · {{ $h['at'] ? \Illuminate\Support\Carbon::parse($h['at'])->format('d/m/Y H:i') : '' }}</span></span>
                                    @if ($edit)<button type="button" style="{{ $btn }}" wire:click="restoreVersion({{ $h['version'] }})">Khôi phục</button>@endif
                                </div>
                            @empty<span class="text-xs" style="opacity:.7">Chưa có phiên bản nào: hồ sơ đang là mặc định.</span>@endforelse
                        </div>
                    @endif
                    <fieldset @disabled(! $edit) style="border:0;padding:0;margin:0">
                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(12rem,1fr));gap:.6rem">
                            <div><label>Tên hiển thị</label><input style="{{ $input }}" wire:model="fName" maxlength="80" data-testid="studio-f-name"></div>
                            <div><label>Chức vụ</label><input style="{{ $input }}" wire:model="fTitle" maxlength="120" data-testid="studio-f-title"></div>
                            <div><label>Avatar</label>
                                <div style="display:flex;gap:.4rem;align-items:center">
                                    <select style="{{ $input }};width:6.5rem" wire:model="fAvatarType"><option value="emoji">Emoji</option><option value="initials">Chữ cái</option></select>
                                    <input style="{{ $input }};width:4.5rem" wire:model="fAvatarValue" maxlength="8" aria-label="Emoji hoặc chữ cái">
                                    <input type="color" wire:model="fAvatarColor" aria-label="Màu" style="width:2.4rem;height:2rem;border:0;background:transparent">
                                </div></div>
                        </div>
                        <label>Tính cách (tối đa {{ \App\Services\Agent\Roles\AgentProfiles::TRAITS_MAX }})</label>
                        <div style="display:flex;gap:.3rem;flex-wrap:wrap;align-items:center" data-testid="studio-traits">
                            @foreach ($fTraits as $i => $t)<span class="ags-chip ags-chip--on">{{ $t }} @if ($edit)<button type="button" wire:click="removeTrait({{ $i }})" aria-label="Bỏ {{ $t }}" style="background:none;border:0;cursor:pointer;color:inherit">×</button>@endif</span>@endforeach
                            @if ($edit)
                                @foreach (array_diff($s['suggested'], $fTraits) as $t)<button type="button" class="ags-chip" wire:click="addTrait('{{ $t }}')">+ {{ $t }}</button>@endforeach
                                <input style="{{ $input }};width:9rem" wire:model="newTrait" wire:keydown.enter.prevent="addTrait" placeholder="Tự thêm" maxlength="40" aria-label="Tự thêm tính cách">
                            @endif
                        </div>
                        <label>Persona (vai trò, giọng, cách làm việc) · {{ mb_strlen($fPersona) }}/{{ \App\Services\Agent\Roles\AgentProfiles::PERSONA_MAX }}</label>
                        <textarea style="{{ $input }}" rows="5" wire:model="fPersona" data-testid="studio-f-persona"></textarea>
                        <label>Quy tắc (markdown ngắn) · {{ mb_strlen($fInstructions) }}/{{ \App\Services\Agent\Roles\AgentProfiles::INSTRUCTIONS_MAX }}</label>
                        <textarea style="{{ $input }}" rows="8" wire:model="fInstructions" data-testid="studio-f-instructions"></textarea>
                    </fieldset>
                    @break

                @case('model')
                    <div class="ags-ph">
                        <div><h3>Mô hình <x-help title="Model của nhân sự">
                            <p>Bậc model đề xuất: {{ $s['role']['tier_label'] }}.</p>
                            <p>Ngân sách bước đặt ở cấu hình Kết nối AI và chính sách phiên chạy (một con số cho cả phiên), không đặt riêng theo vai trò.</p>
                            <p>Chưa gán thì dùng định tuyến theo loại việc.</p>
                        </x-help></h3><div class="ags-desc">Model AI mà nhân sự này gọi khi làm việc.</div></div>
                        <div class="ags-act">@if ($edit && $s['modelWired'])<button type="button" style="{{ $btnPrimary }}" wire:click="mountAction('assign', {{ \Illuminate\Support\Js::from(['role' => $s['key']]) }})">Đổi model</button>@endif</div>
                    </div>
                    <div class="ags-card" data-testid="studio-model">
                        @if ($s['modelWired'])
                            <div class="text-xs" style="opacity:.7">Loại việc AI: <code>{{ implode(', ', $s['tasks']) }}</code></div>
                            <div style="margin-top:.3rem">Model đã gán: <strong>{{ $s['assigned'] ?? 'chưa gán, dùng định tuyến theo loại việc' }}</strong></div>
                        @else
                            Chưa nối vào luồng gọi AI: vai trò này chưa gọi AI trong phiên chạy nên chưa gán model.
                        @endif
                    </div>
                    @break

                @case('knowledge')
                    <div class="ags-ph">
                        <div><h3>Kiến thức & bài học <x-help title="Kiến thức và bài học">
                            <p>Tài liệu riêng và bài học ngắn được đưa vào prompt ở mỗi lần chạy khi đang bật, trong giới hạn {{ number_format($s['max']) }} ký tự.</p>
                            <p>Phần lớn bài học đến từ lần chạy có vấn đề, xem Phòng huấn luyện.</p>
                        </x-help></h3><div class="ags-desc">Tài liệu tham chiếu và quy tắc ngắn nhân sự này luôn nhớ.</div></div>
                    </div>
                    <div class="ags-sec">Tài liệu riêng · {{ number_format($s['chars']) }}/{{ number_format($s['max']) }} ký tự</div>
                    <div class="ags-bar" style="margin-bottom:.5rem"><i style="width:{{ min(100, $s['chars'] / $s['max'] * 100) }}%"></i></div>
                    @foreach ($s['docs'] as $d)
                        <div class="ags-card" style="display:flex;justify-content:space-between;gap:.5rem"><span><strong>{{ $d->title }}</strong> <span class="text-xs" style="opacity:.6">· {{ number_format(mb_strlen($d->body)) }} ký tự</span></span>@if ($edit)<button type="button" style="{{ $btn }}" wire:click="deleteDocument({{ $d->id }})" wire:confirm="Xóa tài liệu này?">Xóa</button>@endif</div>
                    @endforeach
                    @if ($edit)
                        <div class="ags-card">
                            <input style="{{ $input }}" wire:model="docTitle" placeholder="Tên tài liệu" maxlength="160" data-testid="studio-doc-title">
                            <textarea style="{{ $input }};margin-top:.4rem" rows="3" wire:model="docBody" placeholder="Dán văn bản (hoặc tải tệp .md/.txt dưới 100 KB)" data-testid="studio-doc-body"></textarea>
                            <div style="display:flex;gap:.5rem;align-items:center;margin-top:.4rem"><input type="file" wire:model="docFile" accept=".md,.txt"><button type="button" style="{{ $btnPrimary }}" wire:click="addDocument" data-testid="studio-doc-add">Thêm tài liệu</button></div>
                        </div>
                    @elseif (count($s['docs']) === 0)
                        <div class="text-xs" style="opacity:.6">Chưa có tài liệu riêng.</div>
                    @endif
                    <div class="ags-sec">Bài học · {{ count(array_filter($s['lessons'], fn ($l) => $l->active)) }} đang bật</div>
                    @forelse ($s['lessons'] as $l)
                        <div class="ags-card" style="display:flex;justify-content:space-between;gap:.5rem;align-items:flex-start" data-testid="studio-lesson">
                            <span style="{{ $l->active ? '' : 'opacity:.5' }}">{{ $l->text }}
                                <span class="text-xs" style="opacity:.6;display:block">{{ $l->source === 'run' ? 'từ lần chạy'.($l->run_id ? ' #'.$l->run_id : '') : 'nhập tay' }}</span></span>
                            @if ($edit)<span style="display:flex;gap:.3rem;flex:none"><button type="button" style="{{ $btn }}" wire:click="toggleLesson({{ $l->id }}, {{ $l->active ? 'false' : 'true' }})">{{ $l->active ? 'Tắt' : 'Bật' }}</button><button type="button" style="{{ $btn }}" wire:click="deleteLesson({{ $l->id }})" wire:confirm="Xóa bài học?">Xóa</button></span>@endif
                        </div>
                    @empty<div class="text-xs" style="opacity:.6">Chưa có bài học.</div>@endforelse
                    @if ($edit)
                        <div style="display:flex;gap:.4rem;margin-top:.4rem"><input style="{{ $input }}" wire:model="lessonText" wire:keydown.enter.prevent="addLesson" maxlength="500" placeholder="Ví dụ: Không dùng số liệu khi chưa có nguồn" data-testid="studio-lesson-text"><button type="button" style="{{ $btnPrimary }}" wire:click="addLesson" data-testid="studio-lesson-add">Thêm</button></div>
                    @endif
                    @break

                @case('brain')
                    <div class="ags-ph">
                        <div><h3>Trí tuệ <x-help title="Quyền đọc Trí tuệ">
                            <p>Chọn Trí tuệ mà vai trò này được đọc kiến thức. Quyền "Đọc và ghi" chưa có: vai trò chỉ đọc.</p>
                            <p>Với Người viết: nếu chọn ít nhất một Trí tuệ, kiến thức khi viết chỉ lấy từ lựa chọn này và các Trí tuệ đã gắn website (không đổi hạng rủi ro hay mức dựa vào AI). Không chọn gì = như cũ.</p>
                        </x-help></h3><div class="ags-desc">Kho kiến thức nhân sự này được phép đọc. <span style="opacity:.8">{{ $s['applied'] ? 'Đang áp dụng khi chạy.' : 'Mới lưu, chưa áp dụng khi chạy.' }}</span></div></div>
                        <div class="ags-act">@if ($edit)<button type="button" style="{{ $btnPrimary }}" wire:click="saveGrants" data-testid="studio-grants-save">Lưu quyền</button>@endif</div>
                    </div>
                    @forelse ($s['brains'] as $b)
                        <label class="ags-card" style="display:flex;justify-content:space-between;align-items:center;margin:0 0 .4rem;opacity:1;font-size:.85rem">
                            <span>{{ $b['name'] }}</span>
                            <select wire:model="grants.{{ $b['id'] }}" @disabled(! $edit) style="{{ $input }};width:7rem"><option value="">Không</option><option value="1">Đọc</option></select>
                        </label>
                    @empty<div class="text-xs" style="opacity:.6">Chưa có Trí tuệ đang hoạt động.</div>@endforelse
                    @break

                @case('skills')
                    <div class="ags-ph">
                        <div><h3>Kỹ năng <x-help title="Kỹ năng">
                            <p>Kỹ năng gán sẵn là playbook học từ claude-seo; kỹ năng tùy chỉnh là văn bản riêng của vai trò, đi vào prompt cùng hồ sơ từ lần chạy kế tiếp.</p>
                            <p>Tắt kỹ năng thì bước đó dùng chỉ dẫn mặc định.</p>
                        </x-help></h3><div class="ags-desc">Playbook đã gán và kỹ năng riêng của nhân sự này.</div></div>
                        <div class="ags-act">
                            @if ($edit)<button type="button" style="{{ $btn }}" wire:click="mountAction('assign', {{ \Illuminate\Support\Js::from(['role' => $s['key']]) }})">Bật/tắt, ghim phiên bản</button>@endif
                            @if ($s['skillsUrl'])<a href="{{ $s['skillsUrl'] }}" style="{{ $btn }};text-decoration:none">Mở tab Kỹ năng</a>@endif
                        </div>
                    </div>
                    @forelse ($s['skills'] as $k)
                        <div class="ags-card" style="display:flex;justify-content:space-between;gap:.5rem;align-items:center;flex-wrap:wrap">
                            <span><code>{{ $k['ref'] }}</code> @if ($k['exists'])<x-studio.badge :tone="$k['status'] === 'active' ? 'ok' : 'muted'">v{{ $k['version'] }} · {{ $k['status'] === 'active' ? 'đang dùng' : 'bản nháp' }}</x-studio.badge> @else <x-studio.badge tone="muted">Chưa có</x-studio.badge>@endif
                                <x-studio.badge :tone="$k['enabled'] ? 'ok' : 'muted'">{{ $k['enabled'] ? 'bật' : 'tắt' }}</x-studio.badge>
                                @if ($k['lineage'])<span class="text-xs" style="opacity:.6;display:block">Kế thừa {{ $k['lineage']['source'] }} ({{ $k['lineage']['license'] }})</span>@endif</span>
                            @if ($edit && $k['exists'])<button type="button" style="{{ $btn }}" wire:click="cloneSkill('{{ $k['ref'] }}')">Nhân bản để sửa</button>@endif
                        </div>
                    @empty<div class="text-xs" style="opacity:.6">Chưa khai báo kỹ năng.</div>@endforelse
                    <div class="ags-sec">Kỹ năng tùy chỉnh</div>
                    @foreach ($s['custom'] as $d)
                        <div class="ags-card" style="display:flex;justify-content:space-between;gap:.5rem" data-testid="studio-custom-skill"><span><strong>{{ $d->title }}</strong> <span class="text-xs" style="opacity:.6">· {{ number_format(mb_strlen($d->body)) }} ký tự</span></span>@if ($edit)<button type="button" style="{{ $btn }}" wire:click="deleteDocument({{ $d->id }})" wire:confirm="Xóa kỹ năng?">Xóa</button>@endif</div>
                    @endforeach
                    @if ($edit)
                        <div class="ags-card">
                            <input style="{{ $input }}" wire:model="skillTitle" placeholder="Tên kỹ năng" maxlength="160" data-testid="studio-skill-title">
                            <textarea style="{{ $input }};margin-top:.4rem" rows="5" wire:model="skillBody" placeholder="Nội dung kỹ năng (văn bản/markdown)" data-testid="studio-skill-body"></textarea>
                            <button type="button" style="{{ $btnPrimary }};margin-top:.4rem" wire:click="addSkillDoc" data-testid="studio-skill-add">Thêm kỹ năng</button>
                        </div>
                    @elseif (count($s['custom']) === 0)
                        <div class="text-xs" style="opacity:.6">Chưa có kỹ năng tùy chỉnh.</div>
                    @endif
                    @break

                @case('lab')
                    <div class="ags-ph">
                        <div><h3>Phòng huấn luyện <x-help title="Dạy lại">
                            <p>Các lần chạy gần đây có vấn đề với vai trò này. "Dạy lại" biến lỗi thành bài học: sửa câu cho đúng ý rồi lưu.</p>
                        </x-help></h3><div class="ags-desc">Biến lần chạy có vấn đề thành bài học.</div></div>
                    </div>
                    @forelse ($s['issues'] as $i)
                        <div class="ags-card" data-testid="studio-issue">
                            <div style="display:flex;justify-content:space-between;gap:.5rem"><strong class="text-sm">{{ $i['label'] }}</strong>
                                @if ($i['run_id'])<a href="{{ ($s['runUrl'])($i['run_id']) }}" class="text-xs" style="text-decoration:underline">Phiên #{{ $i['run_id'] }}</a>@endif</div>
                            @if ($i['detail'])<div class="text-xs" style="opacity:.75;margin:.2rem 0">{{ $i['detail'] }}</div>@endif
                            @if ($edit)
                                @if (isset($teach[$i['key']]))
                                    <textarea style="{{ $input }}" rows="2" wire:model="teach.{{ $i['key'] }}" maxlength="500"></textarea>
                                    <button type="button" style="{{ $btnPrimary }};margin-top:.3rem" wire:click="teachRow('{{ $i['key'] }}', {{ $i['run_id'] ?? 'null' }})">Lưu bài học</button>
                                @else
                                    <button type="button" style="{{ $btn }}" wire:click="$set('teach.{{ $i['key'] }}', {{ \Illuminate\Support\Js::from($i['draft']) }})" data-testid="studio-teach">Dạy lại</button>
                                @endif
                            @endif
                        </div>
                    @empty<div class="text-sm" style="opacity:.65">Chưa có lần chạy nào có vấn đề.</div>@endforelse
                    @break

                @case('connect')
                    <div class="ags-ph">
                        <div><h3>Công cụ & dữ liệu <x-help title="Phạm vi thật">
                            <p>Bảng dữ liệu đọc/ghi riêng theo vai trò chưa khai báo trong tệp vai trò; danh sách công cụ là phạm vi thật.</p>
                        </x-help></h3><div class="ags-desc">Nhân sự này được và không được dùng gì.</div></div>
                    </div>
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(15rem,1fr));gap:.5rem">
                        <div class="ags-card"><strong class="text-sm">Công cụ được phép</strong><ul class="text-xs" style="margin:.2rem 0 0;padding-left:1.1rem;list-style:disc">@foreach ($s['role']['tools_allowed'] as $t)<li>{{ $t }}</li>@endforeach</ul></div>
                        <div class="ags-card"><strong class="text-sm">Không được dùng</strong><ul class="text-xs" style="margin:.2rem 0 0;padding-left:1.1rem;list-style:disc">@foreach ($s['role']['tools_denied'] as $t)<li>{{ $t }}</li>@endforeach</ul></div>
                        <div class="ags-card"><strong class="text-sm">Rào chắn</strong><ul class="text-xs" style="margin:.2rem 0 0;padding-left:1.1rem;list-style:disc">@foreach ($s['role']['guardrails'] as $t)<li>{{ $t }}</li>@endforeach</ul></div>
                        <div class="ags-card"><strong class="text-sm">Luật chất lượng</strong>
                            <div class="text-xs" style="margin-top:.2rem">@forelse ($s['rubric'] as $r)<span class="ags-chip" title="{{ $r['rationale'] }}"><code>{{ $r['code'] }}</code> {{ $r['name'] }}</span> @empty Chưa gắn luật nào. @endforelse</div></div>
                    </div>
                    @break

                @case('position')
                    <div class="ags-ph">
                        <div><h3>Vị trí trong luồng <x-help title="Cách đọc">
                            <p>Mỗi loại việc một hàng; bước tô xanh là bước vai trò này phụ trách, bước mờ là của vai trò khác hoặc hệ thống. Nét đứt = bước chưa chạy.</p>
                        </x-help></h3><div class="ags-desc">Nhân sự này làm bước nào trong từng loại việc.</div></div>
                        <div class="ags-act"><a href="{{ $s['pipelineUrl'] }}" style="{{ $btn }};text-decoration:none">Mở Pipeline</a></div>
                    </div>
                    @foreach ($s['positions'] as $j)
                        <div class="ags-card" data-testid="studio-pos-{{ $j['id'] }}">
                            <div style="display:flex;justify-content:space-between;gap:.5rem;margin-bottom:.4rem"><strong class="text-sm">{{ $j['title'] }}</strong><span class="text-xs" style="opacity:.65">{{ $j['mine'] ? $j['mine'].' bước' : 'không tham gia' }}{{ $j['status'] === 'planned' ? ' · chưa chạy' : '' }}</span></div>
                            <div class="ags-flow">
                                @foreach ($j['steps'] as $st)
                                    @if (! $loop->first)<span aria-hidden="true" style="opacity:.4">›</span>@endif
                                    <span class="ags-step {{ $st['mine'] ? 'ags-step--mine' : '' }} {{ $st['planned'] ? 'ags-step--planned' : '' }}" data-mine="{{ $st['mine'] ? 1 : 0 }}">{{ $st['label'] }}</span>
                                @endforeach
                            </div>
                            @if ($j['tasks'])<ul class="text-xs" style="margin:.4rem 0 0;padding-left:1.1rem;list-style:disc;opacity:.85">@foreach ($j['tasks'] as $t)<li>{{ $t }}</li>@endforeach</ul>@endif
                        </div>
                    @endforeach
                    @break

                @case('activity')
                    @php $a = $s['activity']; @endphp
                    <div class="ags-ph">
                        <div><h3>Hoạt động</h3><div class="ags-desc">Các phiên gần đây của nhân sự này (30 ngày).</div></div>
                    </div>
                    @if ($a['steps'] > 0)
                        <div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:.5rem;margin-bottom:.8rem" data-testid="studio-activity">
                            <div class="ags-card"><div class="text-xs" style="opacity:.7">Số bước</div><strong>{{ number_format($a['steps']) }}</strong></div>
                            <div class="ags-card"><div class="text-xs" style="opacity:.7">Tỉ lệ ok</div><strong>{{ $a['ok_rate'] === null ? '—' : $a['ok_rate'].'%' }}</strong></div>
                            <div class="ags-card"><div class="text-xs" style="opacity:.7">Chi phí</div><strong>{{ number_format($a['cost_vnd']) }} ₫</strong></div>
                        </div>
                    @endif
                    @forelse ($a['runs'] as $r)
                        <div class="ags-card" style="display:flex;justify-content:space-between;gap:.5rem"><span>{{ $r['title'] }} <x-studio.badge :tone="$statusTone[$r['status']] ?? 'muted'">{{ $r['status'] }}</x-studio.badge></span><a href="{{ ($s['runUrl'])($r['id']) }}" class="text-xs" style="text-decoration:underline">Xem diễn biến</a></div>
                    @empty<div class="text-sm" style="opacity:.65" data-testid="studio-activity">Chưa có hoạt động.</div>@endforelse
                    @break
            @endswitch
            </div>
        </div>
    </div>
</div>
