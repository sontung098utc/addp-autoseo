<?php

namespace App\Integrations\WordPress\Seo;

class YoastAdapter extends KeyMapAdapter
{
    protected function keys(): array
    {
        return ['title' => '_yoast_wpseo_title', 'description' => '_yoast_wpseo_metadesc', 'focus' => '_yoast_wpseo_focuskw'];
    }
}
