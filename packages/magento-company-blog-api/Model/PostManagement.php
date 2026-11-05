<?php

namespace Company\BlogApi\Model;

use Company\BlogApi\Api\Data\PostInterface;
use Company\BlogApi\Api\PostManagementInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

class PostManagement implements PostManagementInterface
{
    protected $postFactory;
    protected $postCollectionFactory;

    public function __construct(
        \Magento\Framework\ObjectManagerInterface $objectManager
    ) {
        // Khởi tạo Mageplaza Post Factory nếu module đã cài đặt
        if (class_exists(\Mageplaza\Blog\Model\PostFactory::class)) {
            $this->postFactory = $objectManager->get(\Mageplaza\Blog\Model\PostFactory::class);
            $this->postCollectionFactory = $objectManager->get(\Mageplaza\Blog\Model\ResourceModel\Post\CollectionFactory::class);
        }
    }

    public function saveDraft(PostInterface $post)
    {
        $publicationKey = $post->getPublicationKey();
        if (empty($publicationKey)) {
            throw new LocalizedException(__('Publication key là bắt buộc để chống đăng trùng.'));
        }

        // Kiểm tra xem bài đã tồn tại theo publication_key chưa
        $existing = $this->findPostByPublicationKey($publicationKey);
        $magePost = $existing ?: $this->createNewMageplazaPost();

        $magePost->setData('name', $post->getTitle());
        $magePost->setData('url_key', $post->getUrlKey());
        $magePost->setData('post_content', $post->getPostContent());
        $magePost->setData('short_description', $post->getShortDescription());
        $magePost->setData('publication_key', $publicationKey);
        $magePost->setData('enabled', 0); // Trạng thái nháp (Draft)
        $magePost->setData('store_ids', $post->getStoreIds());

        if ($post->getImage()) {
            $magePost->setData('image', $post->getImage());
        }

        $magePost->save();

        $post->setPostId($magePost->getId());
        $post->setStatus(0);
        return $post;
    }

    public function getByPublicationKey($publicationKey)
    {
        $magePost = $this->findPostByPublicationKey($publicationKey);
        if (!$magePost || !$magePost->getId()) {
            throw new NoSuchEntityException(__('Không tìm thấy bài viết với publication_key: %1', $publicationKey));
        }

        $postData = new \Company\BlogApi\Model\Data\Post([
            PostInterface::POST_ID => $magePost->getId(),
            PostInterface::PUBLICATION_KEY => $publicationKey,
            PostInterface::TITLE => $magePost->getData('name'),
            PostInterface::URL_KEY => $magePost->getData('url_key'),
            PostInterface::STATUS => (int) $magePost->getData('enabled'),
        ]);

        return $postData;
    }

    public function publish($postId)
    {
        $magePost = $this->createNewMageplazaPost()->load($postId);
        if (!$magePost->getId()) {
            throw new NoSuchEntityException(__('Không tìm thấy bài viết có ID: %1', $postId));
        }

        $magePost->setData('enabled', 1); // 1 = Xuất bản công khai
        $magePost->setData('publish_date', date('Y-m-d H:i:s'));
        $magePost->save();

        $postData = new \Company\BlogApi\Model\Data\Post([
            PostInterface::POST_ID => $magePost->getId(),
            PostInterface::TITLE => $magePost->getData('name'),
            PostInterface::STATUS => 1,
        ]);

        return $postData;
    }

    protected function findPostByPublicationKey($key)
    {
        if (!$this->postCollectionFactory) {
            return null;
        }

        $collection = $this->postCollectionFactory->create();
        $collection->addFieldToFilter('publication_key', $key);
        return $collection->getFirstItem();
    }

    protected function createNewMageplazaPost()
    {
        if (!$this->postFactory) {
            throw new LocalizedException(__('Module Mageplaza Blog chưa được cài đặt hoặc kích hoạt trên Magento.'));
        }

        return $this->postFactory->create();
    }
}
