<?php

namespace App\Filament\Support\Article;

/** CSS cho khung xem trước bài (iframe sandbox): trình bày các khối bố cục AI được phép dùng. Chỉ để xem trước, không đổi HTML đã lưu. */
final class PreviewCss
{
    public const STYLE = 'body{font-family:system-ui,sans-serif;line-height:1.7;margin:16px;color:#1f2937;max-width:820px}'
        .'h2{margin:1.6em 0 .5em;padding-bottom:.25em;border-bottom:2px solid #e5e7eb;font-size:1.4em}h3{margin:1.2em 0 .4em;font-size:1.15em}'
        .'img{max-width:100%;height:auto;border-radius:8px}figure{margin:1.2em 0}figcaption{font-size:.85em;color:#6b7280;text-align:center}'
        .'blockquote{margin:1em 0;padding:.6em 1em;border-left:4px solid #93c5fd;background:#f0f9ff;border-radius:0 6px 6px 0}'
        .'table{border-collapse:collapse;width:100%;margin:1em 0}th,td{border:1px solid #d1d5db;padding:.5em .7em;text-align:left}th{background:#f3f4f6}tbody tr:nth-child(even){background:#f9fafb}'
        .'.autoseo-summary,.autoseo-callout{margin:1.2em 0;padding:.8em 1.1em;border-radius:8px;background:#eff6ff;border:1px solid #bfdbfe}'
        .'.autoseo-callout--warning{background:#fef2f2;border-color:#fecaca}.autoseo-callout--tip{background:#f0fdf4;border-color:#bbf7d0}'
        .'.autoseo-toc{margin:1em 0;padding:.8em 1.4em;background:#f9fafb;border-radius:8px}'
        .'details{margin:.6em 0;padding:.5em .9em;border:1px solid #e5e7eb;border-radius:8px}summary{cursor:pointer;font-weight:600}'
        .'sup.autoseo-cite,sup.autoseo-note{font-size:.75em;margin-left:1px;cursor:help}sup.autoseo-note a{color:#b45309;text-decoration:none}.autoseo-notes{margin-top:1.6em;padding:.6em 1em;border-left:3px solid #fcd34d;background:#fffbeb;font-size:.9em}.autoseo-notes ol{margin:.3em 0 0 1.2em}sup.autoseo-cite a{color:#2563eb;text-decoration:none}.autoseo-refs li{margin:.3em 0}'
        .'.autoseo-refs{margin-top:2em;padding-top:.8em;border-top:1px solid #d1d5db;font-size:.9em;color:#4b5563}';
}
