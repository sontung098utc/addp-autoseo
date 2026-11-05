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

    public function getPostId();
    public function setPostId($postId);

    public function getPublicationKey();
    public function setPublicationKey($key);

    public function getTitle();
    public function setTitle($title);

    public function getUrlKey();
    public function setUrlKey($urlKey);

    public function getPostContent();
    public function setPostContent($content);

    public function getShortDescription();
    public function setShortDescription($description);

    public function getStoreIds();
    public function setStoreIds(array $storeIds);

    public function getCategoryIds();
    public function setCategoryIds(array $categoryIds);

    public function getImage();
    public function setImage($image);

    public function getStatus();
    public function setStatus($status);
}
