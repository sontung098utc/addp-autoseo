{{-- Bảng dữ liệu có tìm kiếm, lọc theo cột, sắp xếp, chọn cột hiển thị, phân trang. Dữ liệu truyền dạng JSON và hiển thị bằng x-text (không HTML thô).
     columns: [key,label,type(text|number|badge|link|delta|mono|actions),align,sortable,filter,visible,searchable,tones,good,unit,decimals,suffix,actions]
     rows: mảng assoc, mỗi dòng nên có 'id'; badge: chuỗi hoặc {text,tone}; link: {text,href}; delta: số; '_tone' tô viền trái (ok|warn|danger|info). --}}
@props(['id', 'columns' => [], 'rows' => [], 'pageSize' => 25, 'search' => 'Tìm trong bảng', 'selectMethod' => null, 'selectedId' => null, 'empty' => 'Không có dữ liệu', 'selectable' => false, 'selectModel' => null, 'selected' => []])
@php
    $cfg = [
        'id' => (string) $id,
        'columns' => array_values($columns),
        'rows' => array_values($rows),
        'pageSize' => (int) $pageSize,
        'selectMethod' => $selectMethod,
        'selectedId' => $selectedId,
        'selectable' => (bool) $selectable,
        'selectModel' => $selectModel,
        'selected' => array_values($selected),
    ];
    $key = 'studio-dt-'.$id.'-'.substr(md5(json_encode($cfg['rows']).json_encode($cfg['columns']).json_encode($selectedId)), 0, 8);
@endphp
<div class="ss-dt" wire:key="{{ $key }}" x-data="studioTable(@js($cfg))">
    <div class="ss-dt__bar">
        <input type="search" x-model="query" placeholder="{{ $search }}" aria-label="{{ $search }}">
        <template x-for="c in cfg.columns.filter(c => c.filter)" :key="c.key">
            <select x-model="filters[c.key]" x-bind:aria-label="'Lọc theo ' + c.label">
                <option value="" x-text="c.label + ': tất cả'"></option>
                <template x-for="o in options(c)" :key="o"><option x-bind:value="o" x-text="o"></option></template>
            </select>
        </template>
        <button type="button" class="ss-dt__btn" x-show="hasFilters()" x-cloak x-on:click="reset()">Xóa lọc</button>
        <span class="ss-dt__spacer"></span>
        <span class="ss-dt__count" x-show="cfg.selectable && selected.length" x-cloak x-text="'Đã chọn ' + selected.length"></span>
        <span class="ss-dt__count" x-text="countText"></span>
        <div class="ss-dt__menuwrap" x-on:click.outside="menu = false" x-on:keydown.escape="menu = false">
            <button type="button" class="ss-dt__btn" aria-haspopup="true" x-bind:aria-expanded="menu ? 'true' : 'false'" x-on:click="menu = !menu">Cột hiển thị</button>
            <div class="ss-dt__menu" x-show="menu" x-cloak role="group" aria-label="Chọn cột hiển thị">
                <template x-for="c in cfg.columns" :key="c.key"><label><input type="checkbox" x-model="visible[c.key]"><span x-text="c.label"></span></label></template>
            </div>
        </div>
    </div>
    <div class="ss-dt__scroll">
        <table class="ss-dt__table">
            <thead><tr>
                <th scope="col" x-show="cfg.selectable" x-cloak style="width:2rem"><input type="checkbox" x-bind:checked="allChecked" x-on:change="toggleAll($event.target.checked)" aria-label="Chọn tất cả các dòng đang lọc"></th>
                <template x-for="c in shownCols" :key="c.key">
                    <th scope="col" x-bind:class="{ 'is-right': c.align === 'right', 'is-center': c.align === 'center' }" x-bind:aria-sort="c.sortable ? ariaSort(c.key) : null">
                        <template x-if="c.sortable"><button type="button" class="ss-dt__sortbtn" x-on:click="toggleSort(c.key)"><span x-text="c.label"></span><span aria-hidden="true" x-text="sortIcon(c.key)"></span></button></template>
                        <template x-if="!c.sortable"><span x-text="c.label"></span></template>
                    </th>
                </template>
            </tr></thead>
            <tbody>
                <template x-for="(row, i) in pageRows" :key="row.id ?? i">
                    <tr x-bind:class="rowClass(row)" x-on:click="selectRow(row)" x-bind:style="cfg.selectMethod ? 'cursor:pointer' : ''">
                        <td x-show="cfg.selectable" x-cloak><input type="checkbox" x-bind:checked="selected.includes(row.id)" x-on:change="toggle(row.id)" x-on:click.stop aria-label="Chọn dòng"></td>
                        <template x-for="c in shownCols" :key="c.key">
                            <td x-bind:class="{ 'is-right': c.align === 'right' || c.type === 'number' || c.type === 'delta', 'is-center': c.align === 'center' }">
                                <template x-if="c.type === 'badge'"><span class="ss-badge" x-bind:class="'ss-badge--' + badgeTone(row, c)" x-text="fmt(row, c)"></span></template>
                                <template x-if="c.type === 'delta'"><span class="ss-delta" x-bind:class="deltaView(row, c).cls" x-bind:title="deltaView(row, c).label"><span aria-hidden="true" x-text="deltaView(row, c).icon"></span><span x-text="deltaView(row, c).text"></span><span class="sr-only" x-text="deltaView(row, c).label"></span></span></template>
                                <template x-if="c.type === 'link'"><a x-bind:href="href(row, c)" target="_blank" rel="noopener noreferrer" x-on:click.stop x-text="fmt(row, c)"></a></template>
                                <template x-if="c.type === 'actions'"><span><template x-for="a in c.actions" :key="a.method"><button type="button" class="ss-dt__act" x-bind:class="a.tone === 'danger' ? 'ss-dt__act--danger' : ''" x-bind:style="a.tone === 'muted' ? 'color:var(--ss-text-muted);text-decoration:none' : ''" x-on:click.stop="act(a, row)" x-text="a.label"></button></template></span></template>
                                <template x-if="c.type === 'mono'"><span class="ss-mono" x-text="fmt(row, c)"></span></template>
                                <template x-if="!['badge','delta','link','actions','mono'].includes(c.type)"><span x-bind:class="c.type === 'number' ? 'ss-num' : ''" x-text="fmt(row, c)"></span></template>
                            </td>
                        </template>
                    </tr>
                </template>
            </tbody>
        </table>
        <div class="ss-dt__empty" x-show="filtered.length === 0" x-cloak><span x-text="hasFilters() ? 'Không có dòng nào khớp bộ lọc' : @js($empty)"></span></div>
    </div>
    <div class="ss-dt__foot" x-show="filtered.length > 0" x-cloak>
        <span>Trang <span x-text="Math.min(page, pages)"></span>/<span x-text="pages"></span></span>
        <span class="ss-toolbar">
            <label>Mỗi trang <select class="ss-dt__btn" x-model.number="pageSize"><option>10</option><option>25</option><option>50</option><option>100</option></select></label>
            <button type="button" class="ss-dt__btn" x-bind:disabled="page <= 1" x-on:click="page--">Trước</button>
            <button type="button" class="ss-dt__btn" x-bind:disabled="page >= pages" x-on:click="page++">Sau</button>
        </span>
    </div>
</div>
