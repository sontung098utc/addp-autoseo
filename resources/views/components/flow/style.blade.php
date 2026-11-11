{{-- Kiểu dùng chung cho luồng (trang Pipeline và tab Diễn biến của phiên). Nạp một lần mỗi trang. --}}
@once
<style>
    .fl{--fl-line:rgba(128,128,128,.35);--fl-done:#059669;--fl-warn:#d97706;--fl-fail:#b91c1c;--fl-run:#0284c7;--fl-wait:#7c3aed;--fl-mute:rgba(128,128,128,.55)}
    .fl-scroll{overflow-x:auto;padding:.5rem .15rem .75rem}
    .fl-row{display:flex;align-items:stretch;min-width:max-content}
    .fl-node{position:relative;flex:0 0 var(--fl-w,9.5rem);width:var(--fl-w,9.5rem);min-height:4.6rem;display:flex;flex-direction:column;justify-content:center;gap:.3rem;padding:.55rem .65rem;text-align:left;color:inherit;
        background:rgba(128,128,128,.05);border:1.5px solid var(--fl-line);border-radius:.7rem;font-size:.75rem;cursor:pointer;transition:background .12s,border-color .12s}
    button.fl-node:hover{background:rgba(128,128,128,.12)}
    button.fl-node:focus-visible{outline:2px solid var(--fl-run);outline-offset:2px}
    .fl-node__top{display:flex;align-items:center;gap:.4rem;min-width:0}
    .fl-num{flex:none;width:1.15rem;height:1.15rem;border-radius:50%;background:rgba(128,128,128,.22);display:inline-flex;align-items:center;justify-content:center;font-size:.62rem;font-weight:700}
    .fl-name{font-weight:700;font-size:.8rem;line-height:1.2;overflow-wrap:anywhere}
    .fl-sub{display:flex;align-items:center;gap:.35rem;font-size:.68rem;opacity:.85;min-width:0}
    .fl-ico{width:.95rem;height:.95rem;flex:none}
    .fl-av{flex:none;width:1.35rem;height:1.35rem;border-radius:50%;background:rgba(99,102,241,.22);display:inline-flex;align-items:center;justify-content:center;font-size:.68rem;font-weight:700}
    .fl-meta{margin-top:.15rem;font-size:.66rem;opacity:.75;min-height:.9rem;white-space:nowrap}
    .fl-node--planned,.fl-node--pending,.fl-node--idle,.fl-node--skipped{border-style:dashed;opacity:.6}
    .fl-node--human{border-color:var(--fl-warn)}
    .fl-node--done{border-color:var(--fl-done);background:rgba(5,150,105,.07)}
    .fl-node--warn,.fl-node--stale{border-color:var(--fl-warn);background:rgba(217,119,6,.08)}
    .fl-node--failed,.fl-node--blocked{border-color:var(--fl-fail);background:rgba(185,28,28,.07)}
    .fl-node--running{border-color:var(--fl-run);background:rgba(2,132,199,.08);animation:fl-beat 1.6s ease-in-out infinite}
    .fl-node--waiting{border-color:var(--fl-wait);background:rgba(124,58,237,.09);box-shadow:0 0 0 3px rgba(124,58,237,.18)}
    .fl-node.is-sel{box-shadow:0 0 0 2px currentColor}
    .fl-state{display:inline-flex;align-items:center;gap:.25rem;font-weight:700;font-size:.7rem}
    .fl-node--done .fl-state{color:var(--fl-done)}.fl-node--warn .fl-state,.fl-node--stale .fl-state{color:var(--fl-warn)}
    .fl-node--failed .fl-state,.fl-node--blocked .fl-state{color:var(--fl-fail)}.fl-node--running .fl-state{color:var(--fl-run)}.fl-node--waiting .fl-state{color:var(--fl-wait)}
    .fl-wait-pill{display:inline-flex;align-items:center;gap:.25rem;padding:.1rem .5rem;border-radius:999px;background:var(--fl-wait);color:#fff;font-weight:700;font-size:.7rem}
    .fl-link{flex:0 0 var(--fl-gap,2.2rem);position:relative;align-self:center;height:2px;background:var(--fl-mute)}
    .fl-link::after{content:"";position:absolute;right:-1px;top:-4px;border:5px solid transparent;border-left:7px solid var(--fl-mute);border-right:0}
    .fl-link--dashed{background:repeating-linear-gradient(90deg,var(--fl-mute) 0 5px,transparent 5px 9px)}
    .fl-link--dot::before{content:"";position:absolute;left:calc(50% - 4px);top:-3px;width:8px;height:8px;border-radius:50%;background:var(--fl-warn)}
    .fl-link--done,.fl-link--done::after{background-color:var(--fl-done)}.fl-link--done::after{background:none;border-left-color:var(--fl-done)}
    .fl-link--loop{flex-basis:calc(var(--fl-gap,2.2rem) + 1.6rem);height:2px;background:var(--fl-wait)}
    .fl-link--loop::after{border-left-color:var(--fl-wait)}
    .fl-loop{position:absolute;left:50%;top:-1.6rem;transform:translateX(-50%);font-size:.65rem;font-weight:700;padding:.05rem .4rem;border-radius:999px;border:1.5px solid var(--fl-wait);color:var(--fl-wait);white-space:nowrap;background:var(--fl-bg,transparent)}
    .fl-end{flex:0 0 8.6rem;width:8.6rem;display:flex;flex-direction:column;justify-content:center;gap:.15rem;padding:.45rem .65rem;border-radius:999px;font-size:.7rem;min-width:0}
    .fl-end--in{background:rgba(59,130,246,.10);border:1px solid rgba(59,130,246,.4)}
    .fl-end--out{background:rgba(16,185,129,.10);border:1px solid rgba(16,185,129,.4)}
    .fl-end b{font-size:.62rem;letter-spacing:.06em;text-transform:uppercase;opacity:.8}
    .fl-end span.t{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .fl-end a{font-size:.66rem;text-decoration:underline;text-underline-offset:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:inherit}
    @keyframes fl-beat{0%,100%{box-shadow:0 0 0 0 rgba(2,132,199,.45)}50%{box-shadow:0 0 0 6px rgba(2,132,199,0)}}
    @media (prefers-reduced-motion:reduce){.fl-node--running{animation:none}}
</style>
@endonce
