<?php

namespace Company\BlogApi\Api;

interface MediaManagementInterface
{
    /**
     * Tải media hoặc ảnh bài viết
     *
     * @param string $fileName
     * @param string $base64Content
     * @param string $altText
     * @return mixed
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function upload($fileName, $base64Content, $altText = '');
}
