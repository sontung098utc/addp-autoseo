<div style="padding:.75rem 1rem 0">
    <x-chain-context step="Xuất bản" purpose="Lượt đăng thật theo giờ: trạng thái, số lần thử, lỗi và đối soát khi CMS không phản hồi. Muốn sắp ngày dự kiến cho bài thì dùng Lịch tuần."
        :inputs="[['label' => 'Bài đã duyệt', 'url' => \App\Filament\Resources\ArticleResource::getUrl('index'), 'note' => 'Bài viết'], ['label' => 'Lịch tuần', 'url' => \App\Filament\Resources\PublicationResource::scheduleUrl()]]"
        :outputs="[['label' => 'Bài lên website', 'note' => 'cột «Link bài» sau khi đăng'], ['label' => 'Search Console', 'url' => \App\Filament\Pages\SearchConsolePage::getUrl(), 'note' => 'đo hiệu quả']]" :compact="true" />
</div>
