/* SEO Studio: thanh tiến độ mảnh ở mép trên màn hình (kiểu Facebook/YouTube) để người dùng biết thao tác đã được nhận khi máy chủ phản hồi chậm.
 * - Bấm liên kết nội bộ / gửi biểu mẫu thường: thanh chạy ngay, GIỮ liên tục sang trang mới (nhớ bằng sessionStorage) và hoàn tất khi trang tải xong.
 * - Thao tác Livewire (bấm nút, lọc, lưu): hiện thanh nếu chờ quá 350 ms, ẩn khi có phản hồi.
 * Không phụ thuộc thư viện ngoài, không chèn HTML người dùng (chỉ tạo một phần tử cố định). Tôn trọng prefers-reduced-motion. */
(function () {
  'use strict';
  var KEY = 'ss-nav-progress';
  var bar = null, value = 0, timer = null, active = 0, leaving = false, lastTick = 0, hideTimer = null;
  var reduced = false;
  try { reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches; } catch (e) { /* bỏ qua */ }

  function ensure() {
    if (bar) return bar;
    var css = document.createElement('style');
    css.textContent =
      '#ss-progress{position:fixed;top:0;left:0;height:3px;width:100%;z-index:2147483000;pointer-events:none;opacity:0;transform:scaleX(0);transform-origin:0 50%;' +
      'background:linear-gradient(90deg,#0f6b5c,#14816e 60%,#3fa893);box-shadow:0 0 8px rgba(20,129,110,.55);transition:transform .25s ease-out,opacity .3s ease}' +
      '#ss-progress.on{opacity:1}' +
      ':root[data-theme=dark] #ss-progress,.dark #ss-progress{background:linear-gradient(90deg,#14816e,#3fa893 60%,#5eead4);box-shadow:0 0 8px rgba(94,234,212,.5)}' +
      '@media (prefers-reduced-motion:reduce){#ss-progress{transition:none}}';
    (document.head || document.documentElement).appendChild(css);
    bar = document.createElement('div');
    bar.id = 'ss-progress';
    bar.setAttribute('aria-hidden', 'true');
    (document.body || document.documentElement).appendChild(bar);
    return bar;
  }

  function paint() {
    ensure().style.transform = 'scaleX(' + Math.max(0, Math.min(1, value)) + ')';
  }

  function start(from) {
    clearTimeout(hideTimer);
    var b = ensure();
    if (!b.parentNode && document.body) document.body.appendChild(b);
    active++;
    if (timer) return;
    b.classList.add('on');
    value = from || 0.08;
    paint();
    lastTick = Date.now();
    timer = setInterval(function () {
      var now = Date.now();
      // Hộp thoại "rời trang?" (beforeunload) làm treo bộ đếm; nếu sau đó vẫn còn ở trang này nghĩa là người dùng đã hủy rời đi: bỏ thanh.
      if (leaving && now - lastTick > 1500) { leaving = false; reset(); return; }
      lastTick = now;
      // Tiến chậm dần về ~90%, không bao giờ tự đầy: chỉ done() mới hoàn tất.
      value += (0.92 - value) * (reduced ? 0.06 : 0.14) * (0.5 + Math.random() * 0.5) + 0.004;
      paint();
    }, 250);
  }

  function reset() {
    active = 0;
    if (timer) { clearInterval(timer); timer = null; }
    if (bar) { bar.classList.remove('on'); }
    value = 0;
    try { sessionStorage.removeItem(KEY); } catch (e) { /* bỏ qua */ }
  }

  function done() {
    if (active > 0) active--;
    if (active > 0) return;
    if (timer) { clearInterval(timer); timer = null; }
    value = 1;
    paint();
    hideTimer = setTimeout(function () {
      if (bar) bar.classList.remove('on');
      hideTimer = setTimeout(function () { value = 0; paint(); }, 350);
    }, 180);
  }

  /* --- Chuyển trang: giữ thanh liên tục giữa trang cũ và trang mới --- */
  function markNavigating() {
    leaving = true;
    try { sessionStorage.setItem(KEY, String(Date.now())); } catch (e) { /* bỏ qua */ }
  }

  function internalLink(a, e) {
    if (!a || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return false;
    var href = a.getAttribute('href');
    if (!href || href.charAt(0) === '#' || /^(javascript:|mailto:|tel:)/i.test(href)) return false;
    if (a.hasAttribute('download') || a.hasAttribute('wire:navigate') || a.hasAttribute('data-no-progress')) return false;
    var t = a.getAttribute('target');
    if (t && t !== '_self') return false;
    var u;
    try { u = new URL(a.href, location.href); } catch (err) { return false; }
    if (u.origin !== location.origin) return false;
    if (u.pathname === location.pathname && u.search === location.search && u.hash !== '') return false; // chỉ nhảy tới mục trong trang
    return true;
  }

  document.addEventListener('click', function (e) {
    var a = e.target && e.target.closest ? e.target.closest('a[href]') : null;
    // Chạy ở pha nổi bọt trên document: các xử lý của Alpine/Livewire trên chính phần tử đã chạy xong nên defaultPrevented phản ánh đúng.
    if (internalLink(a, e)) { markNavigating(); start(); }
  }, false);

  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (!f || e.defaultPrevented || (f.getAttribute && f.getAttribute('target') === '_blank') || (f.hasAttribute && f.hasAttribute('wire:submit'))) return;
    if (f.matches && f.matches('[wire\\:submit],[wire\\:submit\\.prevent],[data-no-progress]')) return;
    markNavigating(); start();
  }, false);

  // Trang quay lại từ bộ nhớ đệm trình duyệt (nút Back): bỏ thanh đang treo.
  window.addEventListener('pageshow', function (e) { if (e.persisted) reset(); });

  /* --- Trang mới: nếu đến từ một lần bấm, thanh tiếp tục từ ~60% và hoàn tất khi tải xong --- */
  var cameFrom = 0;
  try { cameFrom = parseInt(sessionStorage.getItem(KEY) || '0', 10); } catch (e) { /* bỏ qua */ }
  if (cameFrom && Date.now() - cameFrom < 60000) {
    var go = function () {
      start(0.6);
      var fin = function () { try { sessionStorage.removeItem(KEY); } catch (e) { /* bỏ qua */ } done(); };
      if (document.readyState === 'complete') fin(); else window.addEventListener('load', fin, { once: true });
    };
    if (document.body) go(); else document.addEventListener('DOMContentLoaded', go, { once: true });
  } else {
    try { sessionStorage.removeItem(KEY); } catch (e) { /* bỏ qua */ }
  }

  /* --- Thao tác Livewire: hiện thanh nếu chờ quá 350 ms --- */
  document.addEventListener('livewire:init', function () {
    try {
      window.Livewire.hook('commit', function (ctx) {
        var shown = false;
        var t = setTimeout(function () { shown = true; start(); }, 350);
        var end = function () { clearTimeout(t); if (shown) { shown = false; done(); } };
        ctx.respond(end);
        ctx.fail(end);
      });
    } catch (err) { /* phiên bản Livewire khác: bỏ qua, thanh chuyển trang vẫn hoạt động */ }
  });
})();
