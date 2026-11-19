<?php

namespace Company\BlogApi\Api\Data;

interface PostInterface
{
    const POST_ID = 'post_id';
    const PUBLICATION_KEY = 'publication_key';
    const TITLE = 'title';
    const URL_KEY = 'url_key';
    const POST_CONTENT = 'post_content';
    const SHORT_DESCRIPTION = 'short_description';
    const STORE_IDS = 'store_ids';
    const CATEGORY_IDS = 'category_ids';
    const IMAGE = 'image';
    const STATUS = 'status';
    const CREATED_AT = 'created_at';
    const UPDATED_AT = 'updated_at';
    const URL = 'url';
    const BLOG_DRIVER = 'blog_driver';

    /**
     * @return int|null
     */
    public function getPostId();

    /**
     * @param int $postId
     * @return $this
     */
    public function setPostId($postId);

    /**
     * @return string|null
     */
    public function getPublicationKey();

    /**
     * @param string $key
     * @return $this
     */
    public function setPublicationKey($key);

    /**
     * @return string|null
     */
    public function getTitle();

    /**
     * @param string $title
     * @return $this
     */
    public function setTitle($title);

    /**
     * @return string|null
     */
    public function getUrlKey();

    /**
     * @param string|null $urlKey
     * @return $this
     */
    public function setUrlKey($urlKey);

    /**
     * @return string|null
     */
    public function getPostContent();

    /**
     * @param string|null $content
     * @return $this
     */
    public function setPostContent($content);

    /**
     * @return string|null
     */
    public function getShortDescription();

    /**
     * @param string|null $description
     * @return $this
     */
    public function setShortDescription($description);

    /**
     * @return int[]|null
     */
    public function getStoreIds();

    /**
     * @param int[] $storeIds
     * @return $this
     */
    public function setStoreIds(array $storeIds);

    /**
     * @return int[]|null
     */
    public function getCategoryIds();

    /**
     * @param int[] $categoryIds
     * @return $this
     */
    public function setCategoryIds(array $categoryIds);

    /**
     * @return string|null
     */
    public function getImage();

    /**
     * @param string|null $image
     * @return $this
     */
    public function setImage($image);

    /**
     * @return int|null
     */
    public function getStatus();

    /**
     * @param int $status
     * @return $this
     */
    public function setStatus($status);

    /**
     * Link công khai của bài trên site (có thể rỗng nếu không lấy được)
     *
     * @return string|null
     */
    public function getUrl();

    /**
     * @param string|null $url
     * @return $this
     */
    public function setUrl($url);

    /**
     * Nền tảng blog: magefan | mageplaza
     *
     * @return string|null
     */
    public function getBlogDriver();

    /**
     * @param string|null $driver
     * @return $this
     */
    public function setBlogDriver($driver);
}
