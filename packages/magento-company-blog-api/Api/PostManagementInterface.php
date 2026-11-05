<?php

namespace Company\BlogApi\Api;

use Company\BlogApi\Api\Data\PostInterface;

interface PostManagementInterface
{
    /**
     * Tạo hoặc cập nhật bài viết nháp
     *
     * @param \Company\BlogApi\Api\Data\PostInterface $post
     * @return \Company\BlogApi\Api\Data\PostInterface
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function saveDraft(PostInterface $post);

    /**
     * Tra cứu bài viết theo publication_key
     *
     * @param string $publicationKey
     * @return \Company\BlogApi\Api\Data\PostInterface
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getByPublicationKey($publicationKey);

    /**
     * Xuất bản bài viết sang trạng thái công khai
     *
     * @param int $postId
     * @return \Company\BlogApi\Api\Data\PostInterface
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function publish($postId);
}
