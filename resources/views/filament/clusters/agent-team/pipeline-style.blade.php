<x-flow.style />
<style>
    /* ===== Lưới thẻ luồng ===== */
    .pc-grid{display:grid;gap:1.1rem;grid-template-columns:repeat(3,minmax(0,1fr))}
    @media (max-width:1100px){.pc-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
    @media (max-width:680px){.pc-grid{grid-template-columns:minmax(0,1fr)}}
    .pc{display:flex;flex-direction:column;text-align:left;padding:0;overflow:hidden;color:inherit;font:inherit;cursor:pointer;background:var(--fl-card);border:1px solid var(--fl-line);border-radius:1rem;box-shadow:var(--fl-shadow);transition:transform .15s,box-shadow .15s,border-color .15s}
    .pc:hover{transform:translateY(-3px);box-shadow:0 10px 26px rgba(15,23,42,.14);border-color:var(--tone)}
    .pc:focus-visible{outline:3px solid var(--tone);outline-offset:2px}
    .pc__illu{height:9.5rem;background:linear-gradient(135deg,color-mix(in srgb,var(--tone) 20%,transparent),color-mix(in srgb,var(--tone) 5%,transparent));border-bottom:1px solid var(--fl-line)}
    .pc__body{display:flex;flex-direction:column;gap:.5rem;padding:.9rem 1rem 1rem;flex:1}
    .pc__title{display:flex;align-items:center;gap:.5rem;flex-wrap:wrap}
    .pc__name{font-size:1.05rem;font-weight:800;line-height:1.2}
    .pc__plain{font-size:.82rem;line-height:1.45;opacity:.75;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;min-height:2.4em}
    .pc__stats{display:flex;flex-wrap:wrap;gap:.35rem .5rem;margin-top:auto;padding-top:.3rem}
    .pc__stat{display:inline-flex;align-items:center;gap:.3rem;font-size:.72rem;padding:.2rem .55rem;border-radius:.5rem;background:var(--fl-soft)}
    .pc__stat b{font-weight:800;font-size:.8rem}
    .pc__stat--live{background:rgba(2,132,199,.14);color:#0369a1}.dark .pc__stat--live{color:#7dd3fc}
    .pc__pulse{display:inline-block;width:.55rem;height:.55rem;border-radius:50%;background:var(--fl-run);animation:fl-beat 1.4s ease-in-out infinite}
    .pc__human{align-self:flex-start}
    .pv-tag{display:inline-flex;align-items:center;gap:.3rem;font-size:.65rem;font-weight:700;padding:.1rem .5rem;border-radius:999px;background:rgba(100,116,139,.18)}
    .pv-tag--ok{background:rgba(5,150,105,.16);color:#047857}.dark .pv-tag--ok{color:#6ee7b7}
    .pv-tag--live{background:rgba(2,132,199,.16);color:#0369a1}.dark .pv-tag--live{color:#7dd3fc}
    .pv-tag--bad{background:rgba(220,38,38,.14);color:#b91c1c}.dark .pv-tag--bad{color:#fca5a5}
    .pv-tag--warn{background:rgba(245,158,11,.2)}
    /* ===== Popup ===== */
    .pm{position:fixed;inset:0;z-index:200;display:flex;align-items:center;justify-content:center;padding:1.25rem}
    .pm__back{position:absolute;inset:0;background:rgba(15,23,42,.55);backdrop-filter:blur(2px)}
    .pm__box{position:relative;display:flex;flex-direction:column;width:min(1100px,100%);max-height:85vh;background:var(--fl-card);border-radius:1.1rem;border:1px solid var(--fl-line);box-shadow:0 24px 60px rgba(15,23,42,.35);overflow:hidden}
    .pm__head{display:flex;flex-wrap:wrap;gap:.6rem 1rem;align-items:center;justify-content:space-between;padding:.85rem 1.1rem;border-bottom:1px solid var(--fl-line)}
    .pm__titles{display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;min-width:0}
    .pm__titles h3{margin:0;font-size:1.25rem;font-weight:800}
    .pm__actions{display:flex;gap:.5rem;align-items:center;flex-wrap:wrap}
    .pm__x{display:inline-flex;align-items:center;justify-content:center;width:2.2rem;height:2.2rem;border-radius:.6rem;border:1px solid var(--fl-line);background:transparent;color:inherit;cursor:pointer}
    .pm__x:hover{background:var(--fl-soft)}.pm__x:focus-visible{outline:3px solid var(--fl-run)}
    .pm__scroll{overflow-y:auto;padding:1rem 1.1rem 1.3rem;overscroll-behavior:contain}
    .pv-flow__plain{margin:0 0 .6rem;font-size:.88rem;opacity:.8}
    .pm__kpis{display:flex;flex-wrap:wrap;gap:.4rem .6rem;margin-bottom:.9rem}
    .pm__kpis span{font-size:.75rem;padding:.25rem .65rem;border-radius:.5rem;background:var(--fl-soft)}
    .pm__kpis b{font-size:.85rem}
    .pv-legend{display:flex;flex-wrap:wrap;gap:.3rem 1rem;font-size:.7rem;opacity:.7;margin-top:.7rem}
    .pv-legend span{display:inline-flex;align-items:center;gap:.25rem}
    .pm__detail{margin-top:.9rem;border:1px solid var(--fl-line);border-radius:.85rem;padding:.8rem 1rem;background:var(--fl-soft);min-height:3rem}
    .pm__hint{margin:0;font-size:.8rem;opacity:.65}
    .pm__dhead{display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;margin-bottom:.6rem}
    .pm__dl{display:grid;grid-template-columns:repeat(auto-fit,minmax(15rem,1fr));gap:.65rem 1.2rem;margin:0}
    .pm__dl dt{font-size:.65rem;text-transform:uppercase;letter-spacing:.06em;font-weight:700;opacity:.6}
    .pm__dl dd{margin:.1rem 0 0;font-size:.8rem;line-height:1.45;display:flex;align-items:center;gap:.4rem;flex-wrap:wrap}
    .pm__lnk{font-size:.72rem;text-decoration:underline;text-underline-offset:2px;font-weight:600}
    .pm__tech{margin-top:.6rem;font-size:.75rem}.pm__tech summary{cursor:pointer;font-weight:600}
    .pm__recent{margin-top:1rem}
    .pm__recent h4{margin:0 0 .4rem;font-size:.85rem;font-weight:700}
    .pm__run{display:flex;align-items:center;gap:.6rem;flex-wrap:wrap;padding:.45rem .7rem;margin-bottom:.3rem;border:1px solid var(--fl-line);border-radius:.6rem;font-size:.78rem;color:inherit;text-decoration:none;transition:background .12s}
    a.pm__run:hover{background:var(--fl-soft)}
    .pm__runid{font-weight:800}.pm__runsub{flex:1 1 8rem;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.pm__runwhen{opacity:.65;font-size:.72rem}
    @media (max-width:640px){.pm{padding:.35rem}.pm__box{max-height:96vh;border-radius:.9rem}.pm__head{padding:.7rem .8rem}.pm__scroll{padding:.8rem}}
    /* ===== Danh sách phiên đang chạy ===== */
    .pv-run{border:1px solid var(--fl-line);border-radius:.7rem;padding:.6rem .8rem;margin-bottom:.5rem;display:flex;flex-wrap:wrap;gap:.6rem 1rem;align-items:center;background:var(--fl-card)}
    .pv-bar{display:flex;gap:.2rem;flex-wrap:wrap}
    .pv-seg{font-size:.62rem;padding:.1rem .4rem;border-radius:.3rem;background:rgba(128,128,128,.15)}
    .pv-seg--done{background:rgba(16,185,129,.25)}
    .pv-seg--running{background:rgba(14,165,233,.3);font-weight:700}
</style>
