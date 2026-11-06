/* SEO Studio: tab ngang và bảng dữ liệu (Alpine). Không phụ thuộc thư viện ngoài; mọi nội dung hiển thị qua x-text (không x-html). */
document.addEventListener('alpine:init', () => {
  const store = (kind, key, fallback) => {
    try { const v = (kind === 'local' ? localStorage : sessionStorage).getItem(key); return v === null ? fallback : JSON.parse(v); } catch (e) { return fallback; }
  };
  const save = (kind, key, value) => {
    try { (kind === 'local' ? localStorage : sessionStorage).setItem(key, JSON.stringify(value)); } catch (e) { /* bỏ qua */ }
  };

  Alpine.data('studioTabs', (def, persist) => ({
    active: def,
    init() {
      const keys = this.keys();
      const fromHash = location.hash.startsWith('#tab-') ? location.hash.slice(5) : null;
      const saved = persist ? store('session', 'studio.tab.' + persist, null) : null;
      const pick = [fromHash, saved].find((k) => k && keys.includes(k));
      if (pick) this.active = pick;
    },
    keys() { return [...this.$el.querySelectorAll('[role=tab]')].map((e) => e.dataset.key); },
    set(k) {
      this.active = k;
      if (persist) save('session', 'studio.tab.' + persist, k);
    },
    onKey(e) {
      const keys = this.keys(); let i = keys.indexOf(this.active);
      if (e.key === 'ArrowRight') i = (i + 1) % keys.length; else if (e.key === 'ArrowLeft') i = (i - 1 + keys.length) % keys.length;
      else if (e.key === 'Home') i = 0; else if (e.key === 'End') i = keys.length - 1; else return;
      e.preventDefault(); this.set(keys[i]);
      this.$nextTick(() => this.$el.querySelector('[role=tab][data-key="' + keys[i] + '"]')?.focus());
    },
  }));

  Alpine.data('studioTable', (cfg) => ({
    cfg, selected: [...(cfg.selected || [])], query: '', sortKey: null, sortDir: 'asc', filters: {}, visible: {}, page: 1, pageSize: cfg.pageSize || 25, menu: false,
    init() {
      const sk = 'studio.table.' + cfg.id;
      this.visible = store('local', sk + '.cols', null) || Object.fromEntries(cfg.columns.map((c) => [c.key, c.visible !== false]));
      cfg.columns.forEach((c) => { if (!(c.key in this.visible)) this.visible[c.key] = c.visible !== false; });
      const st = store('session', sk + '.state', null);
      if (st) { this.query = st.query || ''; this.sortKey = st.sortKey || null; this.sortDir = st.sortDir || 'asc'; this.filters = st.filters || {}; this.pageSize = st.pageSize || this.pageSize; }
      cfg.columns.filter((c) => c.filter).forEach((c) => { if (!(c.key in this.filters)) this.filters[c.key] = ''; });
      ['query', 'sortKey', 'sortDir', 'filters', 'pageSize'].forEach((p) => this.$watch(p, () => { this.page = 1; this.persist(); }, { deep: true }));
      this.$watch('visible', (v) => save('local', sk + '.cols', v), { deep: true });
      // Máy chủ đổi/xóa lựa chọn (ví dụ sau khi "Thêm vào kế hoạch" xong) thì bảng theo kịp.
      if (cfg.selectModel && this.$wire && this.$wire.$watch) {
        this.$wire.$watch(cfg.selectModel, (v) => { const next = Array.isArray(v) ? [...v] : []; if (JSON.stringify(next) !== JSON.stringify(this.selected)) this.selected = next; });
      }
    },
    persist() { save('session', 'studio.table.' + cfg.id + '.state', { query: this.query, sortKey: this.sortKey, sortDir: this.sortDir, filters: this.filters, pageSize: this.pageSize }); },
    get shownCols() { return cfg.columns.filter((c) => this.visible[c.key] !== false); },
    raw(row, col) { return row[col.key]; },
    text(row, col) {
      const v = this.raw(row, col);
      if (v === null || v === undefined || v === '') return '';
      if (col.type === 'badge' || col.type === 'link') return String(typeof v === 'object' ? (v.text ?? '') : v);
      if (col.type === 'delta') return String(typeof v === 'object' ? (v.value ?? '') : v);
      return String(v);
    },
    num(row, col) {
      let v = this.raw(row, col);
      if (v && typeof v === 'object') v = v.value ?? v.text;
      const n = Number(v); return Number.isFinite(n) ? n : null;
    },
    fmt(row, col) {
      if (col.type === 'number') { const n = this.num(row, col); return n === null ? '—' : n.toLocaleString('vi-VN', { maximumFractionDigits: col.decimals ?? 0, minimumFractionDigits: col.decimals ?? 0 }) + (col.suffix || ''); }
      const t = this.text(row, col); return t === '' ? '—' : t;
    },
    badgeTone(row, col) { const v = this.raw(row, col); if (v && typeof v === 'object' && v.tone) return v.tone; return (col.tones || {})[this.text(row, col)] || 'muted'; },
    deltaView(row, col) {
      const n = this.num(row, col);
      if (n === null) return { cls: 'ss-delta--none', icon: '', text: '—', label: 'Chưa có dữ liệu' };
      const good = col.good || 'up'; const up = n > 0; const flat = n === 0;
      const cls = flat ? 'ss-delta--flat' : ((up === (good === 'up')) ? 'ss-delta--ok' : 'ss-delta--danger');
      const abs = Math.abs(n).toLocaleString('vi-VN', { maximumFractionDigits: col.decimals ?? 1, minimumFractionDigits: col.decimals ?? 1 });
      return { cls, icon: flat ? '■' : (up ? '▲' : '▼'), text: (flat ? '' : (up ? '+' : '−')) + abs + (col.unit || ''), label: (flat ? 'không đổi' : (up ? 'tăng ' : 'giảm ')) + abs + (col.unit || '') };
    },
    href(row, col) { const v = this.raw(row, col); const h = v && typeof v === 'object' ? String(v.href || '') : ''; return /^(https?:\/\/|\/)/.test(h) ? h : '#'; },
    options(col) { return [...new Set(cfg.rows.map((r) => this.text(r, col)).filter((t) => t !== ''))].sort((a, b) => a.localeCompare(b, 'vi')); },
    get filtered() {
      const q = this.query.trim().toLocaleLowerCase('vi');
      let rows = cfg.rows.filter((r) => {
        if (q && !cfg.columns.some((c) => c.searchable !== false && this.text(r, c).toLocaleLowerCase('vi').includes(q))) return false;
        return cfg.columns.every((c) => !c.filter || !this.filters[c.key] || this.text(r, c) === this.filters[c.key]);
      });
      if (this.sortKey) {
        const col = cfg.columns.find((c) => c.key === this.sortKey); const dir = this.sortDir === 'asc' ? 1 : -1;
        const numeric = col && (col.type === 'number' || col.type === 'delta');
        rows = [...rows].sort((a, b) => {
          if (numeric) { const x = this.num(a, col), y = this.num(b, col); if (x === null && y === null) return 0; if (x === null) return 1; if (y === null) return -1; return (x - y) * dir; }
          return this.text(a, col).localeCompare(this.text(b, col), 'vi', { numeric: true }) * dir;
        });
      }
      return rows;
    },
    get pages() { return Math.max(1, Math.ceil(this.filtered.length / this.pageSize)); },
    get pageRows() { const p = Math.min(this.page, this.pages); return this.filtered.slice((p - 1) * this.pageSize, p * this.pageSize); },
    get countText() { return this.filtered.length === cfg.rows.length ? cfg.rows.length + ' dòng' : this.filtered.length + ' / ' + cfg.rows.length + ' dòng'; },
    toggle(id) { this.selected = this.selected.includes(id) ? this.selected.filter((x) => x !== id) : [...this.selected, id]; this.syncSel(); },
    get allChecked() { return this.filtered.length > 0 && this.filtered.every((r) => this.selected.includes(r.id)); },
    toggleAll(on) { const ids = this.filtered.map((r) => r.id); this.selected = on ? [...new Set([...this.selected, ...ids])] : this.selected.filter((x) => !ids.includes(x)); this.syncSel(); },
    syncSel() { if (cfg.selectModel && this.$wire) this.$wire.$set(cfg.selectModel, [...this.selected]); },
    toggleSort(key) { if (this.sortKey !== key) { this.sortKey = key; this.sortDir = 'asc'; } else if (this.sortDir === 'asc') this.sortDir = 'desc'; else { this.sortKey = null; this.sortDir = 'asc'; } },
    sortIcon(key) { return this.sortKey === key ? (this.sortDir === 'asc' ? '▲' : '▼') : ''; },
    ariaSort(key) { return this.sortKey === key ? (this.sortDir === 'asc' ? 'ascending' : 'descending') : 'none'; },
    hasFilters() { return !!this.query || Object.values(this.filters).some(Boolean); },
    reset() { this.query = ''; Object.keys(this.filters).forEach((k) => { this.filters[k] = ''; }); },
    rowClass(row) { return [row._tone ? 'ss-row--' + row._tone : '', cfg.selectedId !== null && cfg.selectedId !== undefined && row.id === cfg.selectedId ? 'is-selected' : ''].join(' '); },
    selectRow(row) { if (cfg.selectMethod && window.Livewire && this.$wire) this.$wire.call(cfg.selectMethod, row.id); },
    act(a, row) { if (this.$wire) this.$wire.call(a.method, row.id); },
  }));
});
