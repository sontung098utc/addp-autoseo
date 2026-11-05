<?php

namespace Company\BlogApi\Model\Data;

use Company\BlogApi\Api\Data\PostInterface;

class Post implements PostInterface
{
    protected array $data = [];

    public function __construct(array $data = [])
    {
        $this->data = $data;
    }

    public function getPostId()
    {
        return $this->data[self::POST_ID] ?? null;
    }

    public function setPostId($postId)
    {
        $this->data[self::POST_ID] = $postId;
        return $this;
    }

    public function getPublicationKey()
    {
        return $this->data[self::PUBLICATION_KEY] ?? null;
    }

    public function setPublicationKey($key)
    {
        $this->data[self::PUBLICATION_KEY] = $key;
        return $this;
    }

    public function getTitle()
    {
        return $this->data[self::TITLE] ?? null;
    }

    public function setTitle($title)
    {
        $this->data[self::TITLE] = $title;
        return $this;
    }

    public function getUrlKey()
    {
        return $this->data[self::URL_KEY] ?? null;
    }

    public function setUrlKey($urlKey)
    {
        $this->data[self::URL_KEY] = $urlKey;
        return $this;
    }

    public function getPostContent()
    {
        return $this->data[self::POST_CONTENT] ?? null;
    }

    public function setPostContent($content)
    {
        $this->data[self::POST_CONTENT] = $content;
        return $this;
    }

    public function getShortDescription()
    {
        return $this->data[self::SHORT_DESCRIPTION] ?? null;
    }

    public function setShortDescription($description)
    {
        $this->data[self::SHORT_DESCRIPTION] = $description;
        return $this;
    }

    public function getStoreIds()
    {
        return $this->data[self::STORE_IDS] ?? [0];
    }

    public function setStoreIds(array $storeIds)
    {
        $this->data[self::STORE_IDS] = $storeIds;
        return $this;
    }

    public function getCategoryIds()
    {
        return $this->data[self::CATEGORY_IDS] ?? [];
    }

    public function setCategoryIds(array $categoryIds)
    {
        $this->data[self::CATEGORY_IDS] = $categoryIds;
        return $this;
    }

    public function getImage()
    {
        return $this->data[self::IMAGE] ?? null;
    }

    public function setImage($image)
    {
        $this->data[self::IMAGE] = $image;
        return $this;
    }

    public function getStatus()
    {
        return $this->data[self::STATUS] ?? 0;
    }

    public function setStatus($status)
    {
        $this->data[self::STATUS] = $status;
        return $this;
    }
}
