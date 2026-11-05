<?php

namespace App\Services\Publishing;

use App\Contracts\PublisherInterface;
use App\Integrations\Magento\MagentoBridgeClient;
use App\Models\Website;
use InvalidArgumentException;

class PublisherManager
{
    /**
     * @var array<string, PublisherInterface|callable>
     */
    protected array $customPublishers = [];

    /**
     * Lấy bộ kết nối CMS phù hợp với website
     */
    public function getPublisher(Website $website): PublisherInterface
    {
        $platform = strtolower($website->cms_type ?? '');

        if (isset($this->customPublishers[$platform])) {
            $custom = $this->customPublishers[$platform];
            return is_callable($custom) ? call_user_func($custom, $website) : $custom;
        }

        return match ($platform) {
            'magento2', 'magento' => app(MagentoBridgeClient::class),
            'wordpress' => app()->bound(PublisherInterface::class)
                ? app(PublisherInterface::class)
                : throw new InvalidArgumentException("WordPress Publisher chưa được đăng ký trong container (phụ trách bởi Dev 2)."),
            default => throw new InvalidArgumentException("Nền tảng CMS không được hỗ trợ: '{$website->cms_type}'."),
        };
    }

    /**
     * Cho phép đăng ký publisher tùy biến (dùng cho testing hoặc Dev 2 gắn thêm provider)
     */
    public function registerPublisher(string $platform, PublisherInterface|callable $publisher): void
    {
        $this->customPublishers[strtolower($platform)] = $publisher;
    }
}
