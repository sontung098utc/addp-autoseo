<?php

namespace App\Filament\Support\Keywords;

use App\Filament\Support\Demo\DemoStore;
use App\Filament\Support\Demo\KeywordsDemoData;

/** Lưu tạm bằng DemoStore (khóa keywords.*). Ở production DemoStore tắt -> luôn rỗng. */
final class DemoKeywordRepository implements KeywordRepository
{
    public function all(): array
    {
        KeywordsDemoData::seedIfEmpty();

        return array_values((array) DemoStore::get('keywords.list', []));
    }

    public function clusters(): array
    {
        KeywordsDemoData::seedIfEmpty();

        return array_values((array) DemoStore::get('keywords.clusters', []));
    }

    public function proposals(): array
    {
        return array_values((array) DemoStore::get('keywords.proposals', []));
    }

    public function saveList(array $list): void
    {
        DemoStore::put('keywords.list', array_values($list));
    }

    public function saveClusters(array $clusters): void
    {
        DemoStore::put('keywords.clusters', array_values($clusters));
    }

    public function saveProposals(array $proposals): void
    {
        DemoStore::put('keywords.proposals', array_values($proposals));
    }

    public function proposalSource(): string
    {
        return DemoStore::get('keywords.proposal_source', 'mock') === 'ai' ? 'ai' : 'mock';
    }

    public function saveProposalSource(string $source): void
    {
        DemoStore::put('keywords.proposal_source', $source === 'ai' ? 'ai' : 'mock');
    }
}
