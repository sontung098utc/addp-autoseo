<?php

namespace App\Integrations\WordPress\Seo;

class RankMathAdapter extends KeyMapAdapter
{
    protected function keys(): array
    {
        return ['title' => 'rank_math_title', 'description' => 'rank_math_description', 'focus' => 'rank_math_focus_keyword'];
    }
}
