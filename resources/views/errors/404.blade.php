<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Không tìm thấy trang</title>
    <style>
        :root{color-scheme:light dark}
        body{font-family:system-ui,sans-serif;margin:0;min-height:100vh;display:grid;place-items:center;background:#f8fafc;color:#0f172a}
        @media (prefers-color-scheme:dark){body{background:#0b1220;color:#e5e7eb}}
        main{max-width:30rem;padding:1.5rem;text-align:center}
        h1{font-size:1.25rem;margin:0 0 .5rem}
        p{opacity:.8;line-height:1.5;margin:0 0 1rem}
        a{display:inline-block;padding:.5rem 1rem;border-radius:.5rem;background:#0f766e;color:#fff;text-decoration:none}
        small{display:block;margin-top:1rem;opacity:.5}
    </style>
</head>
<body>
<main data-testid="error-404">
    <h1>Không tìm thấy trang</h1>
    <p>Đường dẫn có thể đã cũ hoặc bài/bản ghi đã bị xóa.</p>
    <a href="{{ url('/admin') }}">Về trang tổng quan</a>
    <small>Mã lỗi 404</small>
</main>
</body>
</html>
