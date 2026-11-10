<?php

namespace App\Filament\Support\Keywords;

/**
 * Nguồn dữ liệu từ khóa / cụm. Hiện là bản minh họa (DemoKeywordRepository, lưu DemoStore). Khi Dev1 thêm bảng
 * `keywords` + `keyword_clusters` thì viết bản Eloquent và đổi KeywordService::make(), KHÔNG đổi giao diện.
 *
 * list item: {id, keyword, volume:?int, difficulty:?int, intent, source:'csv'|'tay'|'ai', cluster:?string}
 * cluster:   {name, intent, keywords:[keyword...]}
 */
interface KeywordRepository
{
    /** @return list<array<string,mixed>> */
    public function all(): array;

    /** @return list<array<string,mixed>> */
    public function clusters(): array;

    /** @return list<array<string,mixed>> */
    public function proposals(): array;

    /** @param list<array<string,mixed>> $list */
    public function saveList(array $list): void;

    /** @param list<array<string,mixed>> $clusters */
    public function saveClusters(array $clusters): void;

    /** @param list<array<string,mixed>> $proposals */
    public function saveProposals(array $proposals): void;

    /** Nguồn của lần đề xuất gần nhất: 'ai' hoặc 'mock' (minh họa). */
    public function proposalSource(): string;

    public function saveProposalSource(string $source): void;
}
