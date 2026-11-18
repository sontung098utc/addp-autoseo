/* SEO Studio: thanh tiến độ mảnh ở mép trên màn hình (kiểu Facebook/YouTube) để người dùng biết thao tác đã được nhận khi máy chủ phản hồi chậm.
 * - Bấm liên kết nội bộ / gửi biểu mẫu thường: thanh chạy ngay, GIỮ liên tục sang trang mới (nhớ bằng sessionStorage) và hoàn tất khi trang tải xong.
 * - Thao tác Livewire (bấm nút, lọc, lưu): hiện thanh nếu chờ quá SHOW_AFTER_MS, ẩn khi có phản hồi. CHỈ khi chính người dùng vừa thao tác NGAY TRƯỚC lượt gọi
 *   và thao tác nằm trong đúng thành phần đó: các lượt tự làm mới nền (wire:poll của chuông, biểu tượng "đội AI đang chạy", trang đang chạy...) không làm thanh nháy,
 *   kể cả khi tình cờ trùng lúc người dùng đang bấm chỗ khác. Tab đang ẩn thì không hiện thanh.
 * Không phụ thuộc thư viện ngoài, không chèn HTML người dùng (chỉ tạo một phần tử cố định). Tôn trọng prefers-reduced-motion. */
(function () {
  'use strict';
  var BACKGROUND_CALLS = ['loadTabs'], KEY = 'ss-nav-progress', KEYV = 'ss-nav-progress-v', MAX_MS = 25000, lastUser = 0, lastTarget = null, startedAt = 0;
  var SHOW_AFTER_MS = 500, USER_WINDOW_MS = 400, gen = 0; // gen: lượt gọi cũ không được làm thanh của lượt mới kết thúc sớm
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

  /* Đưa thanh về 0 ngay, không có hiệu ứng trượt ngược (lúc này nó đã mờ hẳn). */
  function snap() {
    var b = ensure();
    b.style.transition = 'none';
    b.style.transform = 'scaleX(0)';
    void b.offsetWidth;
    b.style.transition = '';
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
    value = from || 0.12;
    paint();
    lastTick = startedAt = Date.now();
    timer = setInterval(function () {
      var now = Date.now();
      // Chốt an toàn: không có gì xảy ra sau 25 giây (liên kết tải file, mạng treo...) thì bỏ thanh thay vì treo mãi.
      if (now - startedAt > MAX_MS) { reset(); return; }
      // Hộp thoại "rời trang?" (beforeunload) làm treo bộ đếm; nếu sau đó vẫn còn ở trang này nghĩa là người dùng đã hủy rời đi: bỏ thanh.
      if (leaving && now - lastTick > 1500) { leaving = false; reset(); return; }
      lastTick = now;
      // Tiến chậm dần về ~90%, không bao giờ tự đầy: chỉ done() mới hoàn tất.
      value += (0.9 - value) * (reduced ? 0.07 : 0.22) * (0.55 + Math.random() * 0.45) + 0.006; // nhanh ở nửa đầu (≈50% sau 1 s, ≈75% sau 2 s), chậm dần về ~90%
      paint();
      try { sessionStorage.setItem(KEYV, String(value)); } catch (e) { /* bỏ qua */ }
    }, 250);
  }

  function reset() {
    active = 0;
    gen++;
    leaving = false;
    if (timer) { clearInterval(timer); timer = null; }
    if (bar) { bar.classList.remove('on'); }
    value = 0;
    try { sessionStorage.removeItem(KEY); sessionStorage.removeItem(KEYV); } catch (e) { /* bỏ qua */ }
    snap();
  }

  function done() {
    if (active <= 0) return;
    active--;
    if (active > 0) return;
    if (timer) { clearInterval(timer); timer = null; }
    value = 1;
    paint();
    hideTimer = setTimeout(function () {
      if (bar) bar.classList.remove('on');
      hideTimer = setTimeout(function () { value = 0; snap(); }, 400);
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
    if (/\.(pdf|zip|rar|7z|csv|xlsx?|docx?|pptx?|txt|json|xml|png|jpe?g|gif|webp|svg|mp4|mp3)$/i.test(u.pathname)) return false; // tải file: trang không đổi nên thanh sẽ treo
    if (/\/(download|export|storage)(\/|$)/i.test(u.pathname)) return false;
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

  // Mọi kiểu rời trang khác (F5, Ctrl+R, nút tải lại, gõ địa chỉ, Back/Forward, chuyển hướng bằng JavaScript sau khi lưu): trang cũ còn hiện trong lúc
  // chờ máy chủ nên thanh vẫn chạy được. Nếu người dùng hủy hộp thoại "rời trang?" thì bộ phát hiện treo trong start() sẽ tắt thanh.
  window.addEventListener('beforeunload', function () { if (!leaving) { markNavigating(); start(); } });

  // Trang quay lại từ bộ nhớ đệm trình duyệt (nút Back): bỏ thanh đang treo.
  window.addEventListener('pageshow', function (e) { if (e.persisted) reset(); });

  // Tab bị ẩn (không phải do đang chuyển trang): tắt thanh, không để nó chạy ngầm.
  document.addEventListener('visibilitychange', function () { if (document.hidden && !leaving) reset(); });

  /* --- Trang mới: nếu đến từ một lần bấm, thanh tiếp tục từ ~60% và hoàn tất khi tải xong --- */
  var cameFrom = 0, prev = 0.3;
  try { cameFrom = parseInt(sessionStorage.getItem(KEY) || '0', 10); prev = parseFloat(sessionStorage.getItem(KEYV) || '0.3') || 0.3; } catch (e) { /* bỏ qua */ }
  if (cameFrom && Date.now() - cameFrom < 60000) {
    var go = function () {
      start(Math.min(0.85, prev + 0.1)); // tiếp tục từ chỗ trang cũ dừng, không nhảy lùi
      var fin = function () { try { sessionStorage.removeItem(KEY); sessionStorage.removeItem(KEYV); } catch (e) { /* bỏ qua */ } done(); };
      // Hoàn tất khi DOM đã sẵn sàng (nội dung đã hiện), KHÔNG chờ sự kiện load: load còn chờ ảnh và phông chữ ngoài (fonts.bunny.net) nên có thể đến rất muộn.
      if (document.readyState !== 'loading') fin();
      else document.addEventListener('DOMContentLoaded', fin, { once: true });
      window.addEventListener('load', fin, { once: true }); // dự phòng; done() an toàn khi gọi lại
    };
    if (document.body) go(); else document.addEventListener('DOMContentLoaded', go, { once: true });
  } else {
    try { sessionStorage.removeItem(KEY); } catch (e) { /* bỏ qua */ }
  }

  /* --- Phiên hết hạn --- */
  function sessionExpired() {
    if (document.getElementById('ss-expired')) return;
    var box = document.createElement('div');
    box.id = 'ss-expired';
    box.setAttribute('role', 'alert');
    box.style.cssText = 'position:fixed;top:0;left:0;right:0;z-index:2147483001;padding:.7rem 1rem;text-align:center;font-size:.9rem;background:#fff7ed;color:#9a3412;border-bottom:1px solid #fdba74';
    var msg = document.createElement('span');
    msg.textContent = 'Phiên đăng nhập đã hết hạn. Đang tải lại trang để đăng nhập lại… ';
    var link = document.createElement('a');
    link.href = location.href;
    link.textContent = 'Tải lại ngay';
    link.style.textDecoration = 'underline';
    link.setAttribute('data-no-progress', '');
    box.appendChild(msg);
    box.appendChild(link);
    document.body.appendChild(box);
    var last = 0;
    try { last = parseInt(sessionStorage.getItem('ss-419') || '0', 10); sessionStorage.setItem('ss-419', String(Date.now())); } catch (e) { /* bỏ qua */ }
    if (Date.now() - last > 15000) setTimeout(function () { location.reload(); }, 1500); // vừa tải lại mà vẫn 419: không lặp, để người dùng bấm
  }

  /* --- Ghi nhận thao tác thật của người dùng (để phân biệt với tự làm mới nền) --- */
  // Không tính pointerdown/di chuyển chuột: chỉ hành động đã hoàn tất (click, gõ phím, đổi giá trị, gửi biểu mẫu) mới có thể sinh ra một lượt gọi máy chủ.
  ['click', 'keydown', 'input', 'change', 'submit'].forEach(function (ev) {
    document.addEventListener(ev, function (e) { lastUser = Date.now(); lastTarget = e.target; }, true);
  });

  /* Lượt gọi này có phải do người dùng vừa thao tác trong chính thành phần Livewire đó không? (wire:poll không có thao tác nào ngay trước nó) */
  function triggeredByUser(ctx) {
    if (Date.now() - lastUser > USER_WINDOW_MS) return false;
    try {
      var owner = lastTarget && lastTarget.closest ? lastTarget.closest('[wire\\:id]') : null;
      var el = ctx.component && ctx.component.el;
      // Thao tác ngoài mọi thành phần Livewire (thanh bên, khoảng trống) không thể sinh ra lượt gọi; thao tác ở thành phần khác thì lượt này là tự làm mới nền trùng thời điểm.
      if (!owner || (el && owner !== el)) return false;
    } catch (e) { /* bỏ qua */ }
    return true;
  }

  /* --- Thao tác Livewire: hiện thanh nếu chờ quá 350 ms, chỉ khi người dùng vừa thao tác --- */
  document.addEventListener('livewire:init', function () {
    try {
      // Phiên đăng nhập hết hạn (419): Livewire mặc định hỏi bằng hộp thoại rồi hiện cả trang lỗi HTML. Thay bằng thông báo gọn và tự tải lại (về trang đăng nhập).
      window.Livewire.hook('request', function (ctx) {
        ctx.fail(function (r) {
          if (r && r.status === 419) { r.preventDefault(); sessionExpired(); }
        });
      });
      window.Livewire.hook('commit', function (ctx) {
        var shown = false;
        // Nạp ngầm các tab của trang bài (loadTabs, gọi tự động sau khi trang đã hiện) KHÔNG phải việc người dùng đang chờ: không hiện thanh,
        // nếu không thanh cứ chạy trong khi nội dung đã hiện. Tab đang chờ có khung "Đang tải…" riêng.
        var calls = [];
        try { calls = (ctx.commit && ctx.commit.calls) || []; } catch (e) { /* bỏ qua */ }
        var background = calls.length > 0 && calls.every(function (c) { return c && BACKGROUND_CALLS.indexOf(c.method) !== -1; });
        var byUser = !background && !document.hidden && triggeredByUser(ctx); // wire:poll và cập nhật nền không có thao tác trước đó
        var myGen = gen;
        var t = byUser ? setTimeout(function () { if (document.hidden || myGen !== gen) return; shown = true; start(); }, SHOW_AFTER_MS) : 0;
        var end = function () { clearTimeout(t); if (shown) { shown = false; if (myGen === gen) done(); } };
        ctx.respond(end);
        ctx.fail(end);
      });
    } catch (err) { /* phiên bản Livewire khác: bỏ qua, thanh chuyển trang vẫn hoạt động */ }
  });
})();
