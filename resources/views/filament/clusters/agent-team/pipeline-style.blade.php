<x-flow.style />
<style>
    .pv-chips{display:flex;gap:.4rem;overflow-x:auto;padding:.1rem 0 .6rem;margin-bottom:.4rem}
    .pv-chipbtn{flex:none;display:inline-flex;align-items:center;gap:.4rem;padding:.3rem .8rem;border-radius:999px;border:1px solid rgba(128,128,128,.4);background:transparent;color:inherit;font-size:.8rem;cursor:pointer;white-space:nowrap}
    .pv-chipbtn:hover{background:rgba(128,128,128,.12)}
    .pv-chipbtn.is-on{background:rgba(2,132,199,.14);border-color:rgb(2,132,199);font-weight:700}
    .pv-dot{width:.5rem;height:.5rem;border-radius:50%;background:rgba(128,128,128,.5)}.pv-dot--on{background:#059669}
    .pv-flow{border:1px solid rgba(128,128,128,.3);border-radius:.9rem;padding:1rem 1.1rem;background:rgba(128,128,128,.03)}
    .pv-flow__head{display:flex;flex-wrap:wrap;gap:.6rem;align-items:center;justify-content:space-between}
    .pv-flow__title{display:flex;align-items:center;gap:.5rem;min-width:0}
    .pv-flow__title h3{margin:0;font-size:1.05rem;font-weight:700}
    .pv-flow__actions{display:flex;gap:.4rem}
    .pv-flow__plain{margin:.25rem 0 .4rem;font-size:.82rem;opacity:.8}
    .pv-tag{font-size:.65rem;font-weight:600;padding:.05rem .45rem;border-radius:999px;background:rgba(128,128,128,.2)}
    .pv-tag--ok{background:rgba(5,150,105,.18);color:#047857}.pv-tag--off{background:rgba(128,128,128,.18)}
    .pv-tag--warn{background:rgba(245,158,11,.2)}
    .pv-legend{display:flex;flex-wrap:wrap;gap:.3rem 1rem;font-size:.68rem;opacity:.7;margin-top:.2rem}
    .pv-legend span{display:inline-flex;align-items:center;gap:.25rem}
    .pv-run{border:1px solid rgba(128,128,128,.3);border-radius:.7rem;padding:.6rem .8rem;margin-bottom:.5rem;display:flex;flex-wrap:wrap;gap:.6rem 1rem;align-items:center}
    .pv-bar{display:flex;gap:.2rem;flex-wrap:wrap}
    .pv-seg{font-size:.62rem;padding:.1rem .4rem;border-radius:.3rem;background:rgba(128,128,128,.15)}
    .pv-seg--done{background:rgba(16,185,129,.25)}
    .pv-seg--running{background:rgba(14,165,233,.3);font-weight:700}
</style>
