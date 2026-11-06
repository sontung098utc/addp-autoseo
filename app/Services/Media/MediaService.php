<?php

namespace App\Services\Media;

use App\Models\ArticleVersion;
use App\Models\MediaAsset;
use App\Services\Security\UrlSafetyService;
use GuzzleHttp\Client as HttpClient;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

class MediaService
{
    public function __construct(
        protected MediaSecurityValidator $securityValidator,
        protected ImageOptimizer $imageOptimizer,
        protected UrlSafetyService $urlSafetyService,
        protected ?HttpClient $httpClient = null
    ) {
        $this->httpClient = $httpClient ?: new HttpClient(['timeout' => 15]);
    }

    /**
     * Lưu file ảnh tải lên vào thư viện media công ty
     *
     * @param UploadedFile|string $file Đối tượng UploadedFile hoặc đường dẫn file cục bộ
     * @param array $attributes Các thuộc tính đi kèm (website_id, uploaded_by, title, alt_text, tags)
     * @return MediaAsset
     */
    public function storeMedia($file, array $attributes = []): MediaAsset
    {
        $localPath = $file instanceof UploadedFile ? $file->getRealPath() : $file;
        $originalName = $file instanceof UploadedFile ? $file->getClientOriginalName() : basename($localPath);

        // 1. Kiểm tra bảo mật và MIME thực tế từ Magic Bytes
        $securityData = $this->securityValidator->validateFile($localPath);

        // 2. Chống tải trùng file thông qua SHA256 Hash
        $existingAsset = MediaAsset::where('sha256', $securityData['sha256'])
            ->where('website_id', $attributes['website_id'] ?? null)
            ->first();

        if ($existingAsset) {
            // Cập nhật title hoặc alt_text nếu có truyền mới
            if (! empty($attributes['alt_text']) && empty($existingAsset->alt_text)) {
                $existingAsset->update(['alt_text' => $attributes['alt_text']]);
            }
            return $existingAsset;
        }

        // 3. Chuẩn bị thư mục lưu trữ
        $disk = $attributes['disk'] ?? 'public';
        $yearMonth = date('Y/m');
        $fileBaseName = Str::random(20);
        $optimizedFileName = "{$fileBaseName}.webp";
        $thumbnailFileName = "{$fileBaseName}_thumb.webp";

        $storageDir = Storage::disk($disk)->path("media/{$yearMonth}");
        if (! is_dir($storageDir)) {
            mkdir($storageDir, 0755, true);
        }

        $optimizedLocalPath = "{$storageDir}/{$optimizedFileName}";
        $thumbnailLocalPath = "{$storageDir}/{$thumbnailFileName}";

        // 4. Tối ưu nén ảnh và chuyển đổi sang WebP
        $optimizationResult = $this->imageOptimizer->optimize($localPath, $optimizedLocalPath, 'webp');
        $this->imageOptimizer->createThumbnail($optimizedLocalPath, $thumbnailLocalPath, 360, 240);

        $relativeFilePath = "media/{$yearMonth}/{$optimizedFileName}";
        $relativeThumbPath = "media/{$yearMonth}/{$thumbnailFileName}";

        // 5. Chuẩn hóa tiêu đề và Alt Text
        $title = $attributes['title'] ?? pathinfo($originalName, PATHINFO_FILENAME);
        $altText = ! empty($attributes['alt_text'])
            ? $attributes['alt_text']
            : $this->generateDefaultAltText($title, $attributes['focus_keyword'] ?? null);

        // 6. Ghi bản ghi vào cơ sở dữ liệu
        return MediaAsset::create([
            'website_id' => $attributes['website_id'] ?? null,
            'uploaded_by' => $attributes['uploaded_by'] ?? null,
            'disk' => $disk,
            'file_path' => $relativeFilePath,
            'thumbnail_path' => $relativeThumbPath,
            'file_name' => $optimizedFileName,
            'original_name' => $originalName,
            'mime_type' => 'image/webp',
            'file_size' => $optimizationResult['optimized_size'],
            'width' => $optimizationResult['width'],
            'height' => $optimizationResult['height'],
            'sha256' => $securityData['sha256'],
            'title' => $title,
            'alt_text' => $altText,
            'caption' => $attributes['caption'] ?? null,
            'is_featured' => (bool) ($attributes['is_featured'] ?? false),
            'tags' => $attributes['tags'] ?? [],
            'metadata' => [
                'original_size' => $securityData['size'],
                'compression_ratio' => $optimizationResult['compression_ratio'],
                'original_mime' => $securityData['mime'],
            ],
        ]);
    }

    /**
     * Tải và nạp ảnh từ URL từ xa với cơ chế kiểm tra SSRF an toàn
     */
    public function importFromUrl(string $url, array $attributes = [], array $domainAllowlist = []): MediaAsset
    {
        // Kiểm tra an toàn URL và chống SSRF
        $this->urlSafetyService->validateUrl($url, $domainAllowlist);

        $tempPath = tempnam(sys_get_temp_dir(), 'seo_img_');

        try {
            $response = $this->httpClient->get($url, [
                'sink' => $tempPath,
                'timeout' => 20,
            ]);

            if ($response->getStatusCode() !== 200) {
                throw new InvalidArgumentException("Không thể tải ảnh từ URL, mã HTTP: " . $response->getStatusCode());
            }

            $fileName = basename(parse_url($url, PHP_URL_PATH)) ?: 'downloaded_image.jpg';
            $attributes['original_name'] = $attributes['original_name'] ?? $fileName;

            return $this->storeMedia($tempPath, $attributes);
        } finally {
            if (file_exists($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    /**
     * Sinh thẻ Alt text chuẩn SEO
     */
    public function generateDefaultAltText(string $title, ?string $focusKeyword = null): string
    {
        $cleanTitle = trim(preg_replace('/[_-]+/', ' ', $title));

        if (! empty($focusKeyword)) {
            $alt = "Hình ảnh {$focusKeyword} - {$cleanTitle}";
        } else {
            $alt = "Hình ảnh minh họa {$cleanTitle}";
        }

        return Str::limit(strip_tags($alt), 125, '');
    }

    /**
     * Gắn danh sách ảnh vào phiên bản bài viết
     */
    public function attachMediaToArticle(ArticleVersion $version, array $mediaAssetIds, ?int $featuredMediaId = null): void
    {
        $syncData = [];
        $position = 0;

        foreach ($mediaAssetIds as $mediaId) {
            $isFeatured = $featuredMediaId ? ($mediaId === $featuredMediaId) : ($position === 0);
            $syncData[$mediaId] = [
                'is_featured' => $isFeatured,
                'position' => $position++,
            ];
        }

        $version->mediaAssets()->sync($syncData);
    }

    /**
     * Tự động chèn các ảnh trong danh sách vào nội dung HTML của bài viết
     * Phân bổ đều sau các thẻ H2 để giữ nhịp đọc và chuẩn SEO On-Page
     */
    public function injectImagesIntoHtml(ArticleVersion $version, ?Collection $mediaAssets = null): string
    {
        $html = $version->html_content;
        $assets = $mediaAssets ?: $version->mediaAssets()->orderByPivot('position')->get();

        if ($assets->isEmpty()) {
            return $html;
        }

        // Tách theo các thẻ H2
        $pattern = '/(<h2[^>]*>.*?<\/h2>)/i';
        $parts = preg_split($pattern, $html, -1, PREG_SPLIT_DELIM_CAPTURE);

        if (count($parts) <= 1) {
            // Không có thẻ H2, chèn ảnh đầu tiên sau đoạn văn mở đầu
            $pPattern = '/(<\/p>)/i';
            $pParts = preg_split($pPattern, $html, 2, PREG_SPLIT_DELIM_CAPTURE);
            if (count($pParts) > 1) {
                $imageTag = $this->buildFigureHtml($assets->first(), $version);
                return $pParts[0] . $pParts[1] . "\n" . $imageTag . "\n" . ($pParts[2] ?? '');
            }
            return $this->buildFigureHtml($assets->first(), $version) . "\n" . $html;
        }

        $resultHtml = '';
        $assetIndex = 0;
        $totalAssets = $assets->count();

        for ($i = 0; $i < count($parts); $i++) {
            $resultHtml .= $parts[$i];

            // Nếu phần tử hiện tại là thẻ đóng H2 và vẫn còn ảnh để chèn
            if (preg_match($pattern, $parts[$i]) && $assetIndex < $totalAssets) {
                $currentAsset = $assets[$assetIndex];
                $figureHtml = $this->buildFigureHtml($currentAsset, $version);

                // Nếu có đoạn văn tiếp theo, ta thêm ảnh sau đoạn văn đầu tiên của heading đó
                if (isset($parts[$i + 1])) {
                    $nextContent = $parts[$i + 1];
                    $pPos = stripos($nextContent, '</p>');
                    if ($pPos !== false) {
                        $beforeP = substr($nextContent, 0, $pPos + 4);
                        $afterP = substr($nextContent, $pPos + 4);
                        $resultHtml .= $beforeP . "\n" . $figureHtml . "\n" . $afterP;
                        $i++; // Đã xử lý xong phần $parts[$i+1]
                        $assetIndex++;
                        continue;
                    }
                }

                $resultHtml .= "\n" . $figureHtml . "\n";
                $assetIndex++;
            }
        }

        return $resultHtml;
    }

    /**
     * Tạo mã HTML thẻ figure chuẩn SEO Core Web Vitals
     */
    protected function buildFigureHtml(MediaAsset $asset, ArticleVersion $version): string
    {
        $alt = htmlspecialchars($asset->alt_text ?: $this->generateDefaultAltText($asset->title, $version->focus_keyword), ENT_QUOTES, 'UTF-8');
        $caption = htmlspecialchars($asset->caption ?: $asset->title, ENT_QUOTES, 'UTF-8');
        $url = htmlspecialchars($asset->url, ENT_QUOTES, 'UTF-8');
        $width = $asset->width ? " width=\"{$asset->width}\"" : '';
        $height = $asset->height ? " height=\"{$asset->height}\"" : '';

        return "<figure class=\"article-image-block\"><img src=\"{$url}\" alt=\"{$alt}\"{$width}{$height} loading=\"lazy\" decoding=\"async\" /><figcaption>{$caption}</figcaption></figure>";
    }
}
