<?php

namespace Company\BlogApi\Model;

use Company\BlogApi\Api\Data\PostInterface;
use Company\BlogApi\Api\PostManagementInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\ObjectManagerInterface;

class PostManagement implements PostManagementInterface
{
    protected $postFactory;
    protected $postCollectionFactory;
    protected string $driver = 'none'; // 'magefan' | 'mageplaza' | 'none'

    public function __construct(
        ObjectManagerInterface $objectManager,
        ?ResourceConnection $resourceConnection = null
    ) {
        $connection = ($resourceConnection ?: $objectManager->get(ResourceConnection::class))->getConnection();

        if (
            class_exists(\Magefan\Blog\Model\PostFactory::class)
            && $connection->isTableExists($connection->getTableName('magefan_blog_post'))
        ) {
            $this->driver = 'magefan';
            $this->postFactory = $objectManager->get(\Magefan\Blog\Model\PostFactory::class);
            $this->postCollectionFactory = $objectManager->get(\Magefan\Blog\Model\ResourceModel\Post\CollectionFactory::class);
        } elseif (
            class_exists(\Mageplaza\Blog\Model\PostFactory::class)
            && $connection->isTableExists($connection->getTableName('mageplaza_blog_post'))
        ) {
            $this->driver = 'mageplaza';
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

        $existing = $this->findPostByPublicationKey($publicationKey);
        $blogPost = $existing ?: $this->createNewBlogPost();

        $storeIds = $post->getStoreIds();
        if (empty($storeIds) || !is_array($storeIds)) {
            $storeIds = [0];
        }

        if ($this->driver === 'magefan') {
            $blogPost->setData('title', $post->getTitle());
            $blogPost->setData('identifier', mb_substr((string) $post->getUrlKey(), 0, 100));
            $blogPost->setData('content', $post->getPostContent());
            $blogPost->setData('short_content', $post->getShortDescription());
            $blogPost->setData('meta_title', $post->getTitle());
            $blogPost->setData('meta_description', $post->getShortDescription());
            $blogPost->setData('publication_key', $publicationKey);
            $blogPost->setData('is_active', 0);
            $blogPost->setData('stores', $storeIds);
            if ($post->getImage()) {
                $blogPost->setData('featured_img', $post->getImage());
            }
        } else {
            $blogPost->setData('name', $post->getTitle());
            $blogPost->setData('url_key', $post->getUrlKey());
            $blogPost->setData('post_content', $post->getPostContent());
            $blogPost->setData('short_description', $post->getShortDescription());
            $blogPost->setData('publication_key', $publicationKey);
            $blogPost->setData('enabled', 0);
            $blogPost->setData('store_ids', $storeIds);
            if ($post->getImage()) {
                $blogPost->setData('image', $post->getImage());
            }
        }

        $blogPost->save();

        $post->setPostId($blogPost->getId());
        $post->setStatus(0);

        return $post;
    }

    public function getByPublicationKey($publicationKey)
    {
        $blogPost = $this->findPostByPublicationKey($publicationKey);
        if (!$blogPost || !$blogPost->getId()) {
            throw new NoSuchEntityException(__('Không tìm thấy bài viết với publication_key: %1', $publicationKey));
        }

        $isMagefan = $this->driver === 'magefan';

        return new \Company\BlogApi\Model\Data\Post([
            PostInterface::POST_ID => $blogPost->getId(),
            PostInterface::PUBLICATION_KEY => $publicationKey,
            PostInterface::TITLE => $blogPost->getData($isMagefan ? 'title' : 'name'),
            PostInterface::URL_KEY => $blogPost->getData($isMagefan ? 'identifier' : 'url_key'),
            PostInterface::STATUS => (int) $blogPost->getData($isMagefan ? 'is_active' : 'enabled'),
        ]);
    }

    public function publish($postId)
    {
        $blogPost = $this->createNewBlogPost()->load($postId);
        if (!$blogPost->getId()) {
            throw new NoSuchEntityException(__('Không tìm thấy bài viết có ID: %1', $postId));
        }

        $isMagefan = $this->driver === 'magefan';
        $now = date('Y-m-d H:i:s');

        if ($isMagefan) {
            $blogPost->setData('is_active', 1);
            if (!$blogPost->getData('publish_time')) {
                $blogPost->setData('publish_time', $now);
            }
        } else {
            $blogPost->setData('enabled', 1);
            $blogPost->setData('publish_date', $now);
        }
        $blogPost->save();

        return new \Company\BlogApi\Model\Data\Post([
            PostInterface::POST_ID => $blogPost->getId(),
            PostInterface::TITLE => $blogPost->getData($isMagefan ? 'title' : 'name'),
            PostInterface::URL_KEY => $blogPost->getData($isMagefan ? 'identifier' : 'url_key'),
            PostInterface::STATUS => 1,
        ]);
    }

    protected function findPostByPublicationKey($key)
    {
        if (!$this->postCollectionFactory) {
            return null;
        }

        $collection = $this->postCollectionFactory->create();
        $collection->addFieldToFilter('publication_key', $key);
        $collection->setPageSize(1);

        $item = $collection->getFirstItem();

        return ($item && $item->getId()) ? $item : null;
    }

    protected function createNewBlogPost()
    {
        if (!$this->postFactory) {
            throw new LocalizedException(__('Không tìm thấy bảng dữ liệu Blog (Magefan_Blog hoặc Mageplaza_Blog) trên hệ thống Magento này.'));
        }

        return $this->postFactory->create();
    }
}
