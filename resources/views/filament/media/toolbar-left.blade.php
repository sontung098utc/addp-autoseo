@php $page = app('mt.media.page'); @endphp
<style>
    .mt-media-page .fi-ta-header-toolbar { flex-wrap: wrap; row-gap: .5rem }
    .mt-media-page .fi-ta-ctn > *, .mt-media-page .fi-ta-content { border-top-width: 0 !important }
</style>
@if (method_exists($page->getTableRecords(), 'hasPages') && ! $page->getTableRecords()->hasPages())
    <style>.mt-media-page .fi-ta-pagination { display: none }</style> {{-- chỉ một trang: ẩn dòng "Hiện N kết quả / Mỗi trang" cho gọn --}}
@endif
