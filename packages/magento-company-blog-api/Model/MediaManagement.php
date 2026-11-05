<?php

namespace Company\BlogApi\Model;

use Company\BlogApi\Api\MediaManagementInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\LocalizedException;

class MediaManagement implements MediaManagementInterface
{
    protected $filesystem;

    public function __construct(
        \Magento\Framework\Filesystem $filesystem
    ) {
        $this->filesystem = $filesystem;
    }

    public function upload($fileName, $base64Content, $altText = '')
    {
        if (empty($fileName) || empty($base64Content)) {
            throw new LocalizedException(__('Tên file và nội dung ảnh không được để trống.'));
        }

        // Kiểm tra phần mở rộng file an toàn
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];
        if (!in_array($extension, $allowedExtensions)) {
            throw new LocalizedException(__('Định dạng file không được hỗ trợ. Chỉ chấp nhận jpg, jpeg, png, webp.'));
        }

        $mediaDirectory = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);
        $relativeDir = 'mageplaza/blog/post/image';
        $mediaDirectory->create($relativeDir);

        $safeName = preg_replace('/[^a-zA-Z0-9_\.-]/', '_', $fileName);
        $uniqueName = time() . '_' . $safeName;
        $relativePath = $relativeDir . '/' . $uniqueName;

        $decodedData = base64_decode($base64Content);
        if ($decodedData === false) {
            throw new LocalizedException(__('Dữ liệu ảnh base64 không hợp lệ.'));
        }

        $mediaDirectory->writeFile($relativePath, $decodedData);

        return [
            'id' => $uniqueName,
            'file' => $uniqueName,
            'url' => '/media/' . $relativePath,
        ];
    }
}
