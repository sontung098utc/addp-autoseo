<style>
    .pv-flow{border:1px solid rgba(128,128,128,.3);border-radius:.8rem;padding:.8rem .9rem;margin-bottom:.9rem;background:rgba(128,128,128,.04)}
    .pv-flow__head{display:flex;flex-wrap:wrap;gap:.5rem;align-items:center;margin-bottom:.6rem}
    .pv-scroll{overflow-x:auto;padding:.3rem .1rem .6rem}
    .pv-row{display:flex;align-items:stretch;gap:0;min-width:max-content}
    .pv-end{flex:0 0 11rem;border-radius:.7rem;padding:.6rem .7rem;font-size:.75rem;display:flex;flex-direction:column;gap:.3rem}
    .pv-end--in{background:rgba(59,130,246,.10);border:1px solid rgba(59,130,246,.4)}
    .pv-end--out{background:rgba(16,185,129,.10);border:1px solid rgba(16,185,129,.4)}
    .pv-end h4{font-size:.7rem;letter-spacing:.06em;font-weight:700;margin:0}
    .pv-end ul{margin:0;padding-left:1rem}
    .pv-end a,.pv-link{display:inline-block;font-size:.72rem;padding:.1rem .5rem;border-radius:999px;border:1px solid rgba(128,128,128,.45);text-decoration:none;margin:.1rem .15rem .1rem 0;color:inherit}
    .pv-end a:hover,.pv-link:hover{background:rgba(128,128,128,.15)}
    .pv-arrow{flex:0 0 2.6rem;display:flex;flex-direction:column;align-items:center;justify-content:center;position:relative;font-size:.6rem;opacity:.75}
    .pv-arrow::before{content:"";display:block;width:100%;height:2px;background:rgba(128,128,128,.6)}
    .pv-arrow::after{content:"";position:absolute;right:0;top:calc(50% - 4px);border:4px solid transparent;border-left:6px solid rgba(128,128,128,.75);border-right:0}
    .pv-arrow--when{flex-basis:3.6rem}
    .pv-arrow span{position:absolute;top:calc(50% - 1.9rem);width:3.3rem;left:.1rem;text-align:center;line-height:1.1;background:rgba(245,158,11,.18);border-radius:.4rem;padding:0 .25rem;opacity:1}
    .pv-node{flex:0 0 9.5rem;border:1.5px solid rgba(128,128,128,.55);border-radius:.7rem;padding:.5rem .55rem;font-size:.72rem;text-align:left;cursor:pointer;background:rgba(128,128,128,.06);display:flex;flex-direction:column;gap:.3rem;color:inherit}
    .pv-node:hover{background:rgba(128,128,128,.14)}
    .pv-node--planned{border-style:dashed;opacity:.75}
    .pv-node--human{border-color:rgb(245,158,11);box-shadow:0 0 0 1px rgba(245,158,11,.35)}
    .pv-node--live{border-color:rgb(14,165,233);box-shadow:0 0 0 2px rgba(14,165,233,.3)}
    .pv-node__top{display:flex;gap:.35rem;align-items:center}
    .pv-num{font-weight:700;width:1.2rem;height:1.2rem;border-radius:50%;background:rgba(128,128,128,.25);display:inline-flex;align-items:center;justify-content:center;font-size:.65rem;flex:none}
    .pv-label{font-weight:600;font-size:.76rem;line-height:1.2}
    .pv-kind{display:inline-flex;gap:.2rem;align-items:center;font-size:.65rem;opacity:.85}
    .pv-who{display:flex;gap:.35rem;align-items:center;font-size:.68rem}
    .pv-chip{width:1.5rem;height:1.5rem;border-radius:50%;background:rgba(99,102,241,.22);display:inline-flex;align-items:center;justify-content:center;font-weight:700;font-size:.72rem;flex:none}
    .pv-tag{font-size:.62rem;padding:0 .35rem;border-radius:.3rem;background:rgba(128,128,128,.2)}
    .pv-tag--warn{background:rgba(245,158,11,.25)}
    .pv-run{border:1px solid rgba(128,128,128,.3);border-radius:.7rem;padding:.6rem .8rem;margin-bottom:.5rem;display:flex;flex-wrap:wrap;gap:.6rem 1rem;align-items:center}
    .pv-bar{display:flex;gap:.2rem;flex-wrap:wrap}
    .pv-seg{font-size:.62rem;padding:.1rem .4rem;border-radius:.3rem;background:rgba(128,128,128,.15)}
    .pv-seg--done{background:rgba(16,185,129,.25)}
    .pv-seg--running{background:rgba(14,165,233,.3);font-weight:700}
    .pv-desks{display:grid;grid-template-columns:repeat(auto-fill,minmax(15rem,1fr));gap:.7rem}
    .pv-desk{border:1px solid rgba(128,128,128,.3);border-radius:.8rem;padding:.7rem .8rem;font-size:.75rem;display:flex;flex-direction:column;gap:.4rem}
    .pv-avatar{width:2.4rem;height:2.4rem;border-radius:50%;background:rgba(99,102,241,.22);display:inline-flex;align-items:center;justify-content:center;font-weight:700;font-size:1.1rem;flex:none}
    .pv-dot{display:inline-block;width:.55rem;height:.55rem;border-radius:50%;background:rgb(16,185,129);animation:pv-blink 1.2s ease-in-out infinite}
    .pv-dot--idle{background:rgba(128,128,128,.6);animation:none}
    @keyframes pv-blink{50%{opacity:.25}}
    @media (prefers-reduced-motion:reduce){.pv-dot{animation:none}}
</style>
