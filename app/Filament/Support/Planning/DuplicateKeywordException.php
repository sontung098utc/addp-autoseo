<?php

namespace App\Filament\Support\Planning;

use InvalidArgumentException;

class DuplicateKeywordException extends InvalidArgumentException
{
    public function __construct(public readonly int $existingArticleId, string $keyword)
    {
        parent::__construct("Từ khóa chính \"{$keyword}\" đã có bài #{$existingArticleId} trên website này. Mỗi từ khóa chính chỉ nên có một bài để tránh hai bài cạnh tranh nhau.");
    }
}
