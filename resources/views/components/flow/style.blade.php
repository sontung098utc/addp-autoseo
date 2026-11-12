{{-- Kiểu dùng chung cho luồng (popup trang Pipeline và tab Diễn biến của phiên). Nạp một lần mỗi trang. --}}
@once
<style>
    .fl{--fl-line:rgba(100,116,139,.35);--fl-done:#059669;--fl-warn:#d97706;--fl-fail:#dc2626;--fl-run:#0284c7;--fl-wait:#7c3aed;--fl-mute:rgba(100,116,139,.5);--fl-card:#fff;--fl-soft:rgba(100,116,139,.07);--fl-gap:2.2rem;--fl-shadow:0 1px 2px rgba(15,23,42,.06),0 2px 8px rgba(15,23,42,.05)}
    .dark .fl,.dark.fl{--fl-card:#1e293b;--fl-soft:rgba(148,163,184,.10);--fl-line:rgba(148,163,184,.30);--fl-shadow:0 1px 2px rgba(0,0,0,.35)}
    /* Lưới bước: tự xuống dòng theo bề rộng khung chứa, không cuộn ngang. */
    .fl-wrap{container-type:inline-size;width:100%}
    .fl-grid{display:grid;grid-template-columns:repeat(var(--c,1),minmax(0,1fr));column-gap:var(--fl-gap);row-gap:1.5rem;padding:.4rem .1rem}
    @container (min-width:24rem){.fl-grid{--c:2}}
    @container (min-width:40rem){.fl-grid{--c:3}}
    @container (min-width:54rem){.fl-grid{--c:4}}
    @container (min-width:68rem){.fl-grid{--c:5}}
    .fl-cell{position:relative;display:flex;min-width:0}
    .fl-cell>.fl-node{flex:1 1 auto;min-width:0}
    /* Đường nối sang bước kế; ẩn ở cuối mỗi hàng. */
    .fl-link{position:absolute;z-index:1;top:50%;right:calc(var(--fl-gap) * -1);width:var(--fl-gap);height:3px;border-radius:2px;background:var(--fl-mute);transform:translateY(-50%)}
    .fl-link::after{content:"";position:absolute;right:-1px;top:50%;transform:translateY(-50%);border:6px solid transparent;border-left:8px solid var(--fl-mute);border-right:0}
    .fl-link--dashed{background:repeating-linear-gradient(90deg,var(--fl-mute) 0 6px,transparent 6px 10px)}
    .fl-link--dot::before{content:"";position:absolute;left:calc(50% - 5px);top:-3px;width:9px;height:9px;border-radius:50%;background:var(--fl-warn);box-shadow:0 0 0 2px var(--fl-card)}
    .fl-link--done{background:var(--fl-done)}.fl-link--done::after{border-left-color:var(--fl-done)}
    .fl-link--live{background:repeating-linear-gradient(90deg,var(--fl-run) 0 7px,rgba(2,132,199,.28) 7px 14px);background-size:14px 100%;animation:fl-march .7s linear infinite;will-change:background-position}
    .fl-link--live::after{border-left-color:var(--fl-run)}
    .fl-link--loop{background:var(--fl-wait)}.fl-link--loop::after{border-left-color:var(--fl-wait)}
    @container (max-width:23.99rem){.fl-link--live{background:repeating-linear-gradient(180deg,var(--fl-run) 0 7px,rgba(2,132,199,.28) 7px 14px);background-size:100% 14px;animation-name:fl-march-v}.fl-link{top:calc(100% + .1rem);right:auto;left:50%;width:3px;height:1.3rem;transform:translateX(-50%)}.fl-link::after{right:auto;left:50%;top:auto;bottom:-1px;transform:translateX(-50%);border:6px solid transparent;border-top:8px solid var(--fl-mute);border-bottom:0}.fl-grid{row-gap:1.5rem}}
    @container (min-width:24rem) and (max-width:39.99rem){.fl-cell:nth-child(2n) .fl-link{display:none}}
    @container (min-width:40rem) and (max-width:53.99rem){.fl-cell:nth-child(3n) .fl-link{display:none}}
    @container (min-width:54rem) and (max-width:67.99rem){.fl-cell:nth-child(4n) .fl-link{display:none}}
    @container (min-width:68rem){.fl-cell:nth-child(5n) .fl-link{display:none}}
    /* Cung lặp Chấm ↔ Sửa: vòng cung phía trên đường nối + số lần. */
    .fl-loop{position:absolute;z-index:2;top:-.55rem;right:calc(var(--fl-gap) * -1 - .9rem);width:calc(var(--fl-gap) + 1.8rem);display:flex;flex-direction:column;align-items:center;pointer-events:none}
    .fl-loop svg{width:100%;height:1.1rem;overflow:visible}
    .fl-loop b{margin-top:-.1rem;font-size:.68rem;font-weight:700;padding:.1rem .5rem;border-radius:999px;background:var(--fl-wait);color:#fff;white-space:nowrap;box-shadow:var(--fl-shadow)}
    @container (max-width:23.99rem){.fl-loop{right:.5rem;top:auto;bottom:-1.8rem;width:auto}.fl-loop svg{display:none}}
    @container (min-width:24rem) and (max-width:39.99rem){.fl-cell:nth-child(2n) .fl-loop{display:none}}
    @container (min-width:40rem) and (max-width:53.99rem){.fl-cell:nth-child(3n) .fl-loop{display:none}}
    @container (min-width:54rem) and (max-width:67.99rem){.fl-cell:nth-child(4n) .fl-loop{display:none}}
    @container (min-width:68rem){.fl-cell:nth-child(5n) .fl-loop{display:none}}
    /* Nút bước (thẻ lớn) */
    .fl-node{position:relative;display:flex;flex-direction:column;gap:.35rem;padding:.7rem .8rem .65rem;text-align:left;color:inherit;font:inherit;font-size:.78rem;min-height:7.2rem;
        background:var(--fl-card);border:1.5px solid var(--fl-line);border-radius:.85rem;box-shadow:var(--fl-shadow);cursor:pointer;transition:transform .14s ease,box-shadow .14s ease,border-color .14s ease}
    div.fl-node,span.fl-node{cursor:default}
    button.fl-node:hover{transform:translateY(-2px);box-shadow:0 6px 16px rgba(15,23,42,.12)}
    button.fl-node:focus-visible{outline:3px solid var(--fl-run);outline-offset:2px}
    .fl-node__head{display:flex;align-items:center;gap:.4rem;min-width:0}
    .fl-num{flex:none;min-width:1.35rem;height:1.35rem;padding:0 .2rem;border-radius:999px;background:var(--fl-soft);display:inline-flex;align-items:center;justify-content:center;font-size:.68rem;font-weight:800}
    .fl-kind{display:inline-flex;align-items:center;gap:.2rem;font-size:.64rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;opacity:.75}
    .fl-stico{margin-left:auto;width:1.25rem;height:1.25rem;flex:none}
    .fl-name{font-weight:700;font-size:.86rem;line-height:1.25;overflow-wrap:anywhere}
    .fl-desc{font-size:.72rem;line-height:1.35;opacity:.72;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
    .fl-foot{margin-top:auto;display:flex;align-items:center;gap:.4rem;flex-wrap:wrap;font-size:.7rem;min-width:0}
    .fl-ico{width:.95rem;height:.95rem;flex:none}
    .fl-av{flex:none;width:1.55rem;height:1.55rem;border-radius:50%;background:linear-gradient(135deg,rgba(99,102,241,.35),rgba(14,165,233,.3));display:inline-flex;align-items:center;justify-content:center;font-size:.72rem;font-weight:800}
    .fl-who{opacity:.8;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:7.5rem}
    .fl-meta{font-size:.68rem;opacity:.78;display:flex;gap:.5rem;flex-wrap:wrap}
    .fl-chip{display:inline-flex;align-items:center;gap:.2rem;padding:.05rem .45rem;border-radius:999px;font-size:.64rem;font-weight:700;background:var(--fl-soft)}
    .fl-chip--warn{background:rgba(217,119,6,.16);color:#b45309}.dark .fl-chip--warn{color:#fbbf24}
    .fl-chip--human{background:rgba(124,58,237,.14);color:#6d28d9}.dark .fl-chip--human{color:#c4b5fd}
    .fl-node--planned,.fl-node--pending,.fl-node--idle,.fl-node--skipped{border-style:dashed;opacity:.62;box-shadow:none}
    .fl-node--human{border-color:var(--fl-wait);background:linear-gradient(180deg,rgba(124,58,237,.08),transparent 60%),var(--fl-card)}
    .fl-node--done{border-color:var(--fl-done);background:linear-gradient(180deg,rgba(5,150,105,.08),transparent 55%),var(--fl-card)}
    .fl-node--warn,.fl-node--stale{border-color:var(--fl-warn);background:linear-gradient(180deg,rgba(217,119,6,.10),transparent 55%),var(--fl-card)}
    .fl-node--failed,.fl-node--blocked{border-color:var(--fl-fail);background:linear-gradient(180deg,rgba(220,38,38,.09),transparent 55%),var(--fl-card)}
    .fl-node--running{border-color:var(--fl-run);background:linear-gradient(180deg,rgba(2,132,199,.10),transparent 55%),var(--fl-card);box-shadow:0 0 0 3px rgba(2,132,199,.16),var(--fl-shadow)}
    .fl-node--running::after{content:"";position:absolute;inset:-4px;border-radius:calc(.85rem + 4px);border:2px solid var(--fl-run);opacity:0;pointer-events:none;animation:fl-ring 2.2s ease-out infinite;will-change:transform,opacity}
    .fl-node--waiting{border-color:var(--fl-wait);border-width:2px;background:linear-gradient(180deg,rgba(124,58,237,.14),transparent 65%),var(--fl-card);box-shadow:0 0 0 4px rgba(124,58,237,.18),var(--fl-shadow)}
    .fl-node.is-sel{box-shadow:0 0 0 3px var(--fl-run),var(--fl-shadow)}
    .fl-node--done .fl-stico{color:var(--fl-done)}.fl-node--warn .fl-stico,.fl-node--stale .fl-stico{color:var(--fl-warn)}
    .fl-node--failed .fl-stico,.fl-node--blocked .fl-stico{color:var(--fl-fail)}.fl-node--running .fl-stico{color:var(--fl-run)}.fl-node--waiting .fl-stico{color:var(--fl-wait)}
    .fl-state{display:inline-flex;align-items:center;gap:.25rem;font-weight:700;font-size:.74rem}
    .fl-node--done .fl-state{color:var(--fl-done)}.fl-node--warn .fl-state,.fl-node--stale .fl-state{color:var(--fl-warn)}
    .fl-node--failed .fl-state,.fl-node--blocked .fl-state{color:var(--fl-fail)}.fl-node--running .fl-state{color:var(--fl-run)}.fl-node--waiting .fl-state{color:var(--fl-wait)}
    .fl-wait-pill{display:inline-flex;align-items:center;gap:.25rem;padding:.12rem .55rem;border-radius:999px;background:var(--fl-wait);color:#fff;font-weight:700;font-size:.72rem}
    /* Đầu vào / Kết quả */
    .fl-ends{display:grid;grid-template-columns:repeat(auto-fit,minmax(15rem,1fr));gap:.75rem;margin-top:.9rem}
    .fl-end{display:flex;flex-direction:column;gap:.3rem;padding:.7rem .9rem;border-radius:.8rem;font-size:.78rem;min-width:0}
    .fl-end--in{background:rgba(59,130,246,.08);border:1px solid rgba(59,130,246,.35)}
    .fl-end--out{background:rgba(16,185,129,.08);border:1px solid rgba(16,185,129,.35)}
    .fl-end b{font-size:.66rem;letter-spacing:.07em;text-transform:uppercase;opacity:.8}
    .fl-end ul{margin:0;padding-left:1rem;list-style:disc}
    .fl-end a{font-size:.74rem;text-decoration:underline;text-underline-offset:2px;color:inherit;font-weight:600}
    @keyframes fl-beat{0%,100%{box-shadow:0 0 0 0 rgba(2,132,199,.45)}50%{box-shadow:0 0 0 7px rgba(2,132,199,0)}}
    @keyframes fl-ring{0%{opacity:.55;transform:scale(.985)}70%,100%{opacity:0;transform:scale(1.035)}}
    @keyframes fl-march{to{background-position:14px 0}}
    @keyframes fl-march-v{to{background-position:0 14px}}
    @media (prefers-reduced-motion:reduce){.fl-node--running::after{animation:none;opacity:0}.fl-link--live{animation:none}button.fl-node:hover{transform:none}}
    /* Hình minh họa */
    .fl-illu{color:#64748b}
    .fl-illu svg{display:block;width:100%;height:100%}
    .fl-illu .p{fill:#fff;stroke:rgba(100,116,139,.35);stroke-width:2}
    .fl-illu .l{fill:rgba(100,116,139,.28)}
    .dark .fl-illu .p{fill:#1e293b;stroke:rgba(148,163,184,.4)}
    .dark .fl-illu .l{fill:rgba(148,163,184,.3)}
</style>
@endonce
