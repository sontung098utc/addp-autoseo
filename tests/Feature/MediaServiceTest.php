<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\MediaAsset;
use App\Models\User;
use App\Models\Website;
use App\Services\Media\ImageOptimizer;
use App\Services\Media\MediaSecurityValidator;
use App\Services\Media\MediaService;
use App\Services\Security\UrlSafetyService;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaServiceTest extends TestCase
{
    use RefreshDatabase;

    protected MediaService $service;
    protected Website $website;
    protected User $user;
    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->user = User::create([
            'name' => 'Media Admin',
            'email' => 'media@example.com',
            'password' => bcrypt('secret123'),
            'role' => 'admin',
        ]);

        $this->website = Website::create([
            'name' => 'Demo Blog',
            'cms_type' => 'magento2',
            'base_url' => 'https://magento.example.com',
            'store_scope' => 'default',
            'status' => 'active',
            'encrypted_credentials' => ['consumer_key' => 'test'],
        ]);

        $this->service = app(MediaService::class);
        $this->tempDir = sys_get_temp_dir() . '/media_svc_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $files = glob($this->tempDir . '/*');
        foreach ($files as $file) {
            @unlink($file);
        }
        @rmdir($this->tempDir);
        parent::tearDown();
    }

    protected function createFakeImageFile(int $w = 400, int $h = 300, string $name = 'test.jpg', int $r = 50, int $g = 120, int $b = 200): string
    {
        $filePath = $this->tempDir . '/' . $name;
        $img = imagecreatetruecolor($w, $h);
        $color = imagecolorallocate($img, $r, $g, $b);
        imagefilledrectangle($img, 0, 0, $w, $h, $color);
        imagejpeg($img, $filePath, 90);
        imagedestroy($img);

        return $filePath;
    }

    public function test_it_stores_uploaded_media_asset(): void
    {
        $sourceFile = $this->createFakeImageFile(500, 350, 'factory_plant.jpg');

        $media = $this->service->storeMedia($sourceFile, [
            'website_id' => $this->website->id,
            'uploaded_by' => $this->user->id,
            'title' => 'Nhà máy sản xuất GMP',
            'focus_keyword' => 'quy trình sản xuất dược phẩm',
        ]);

        $this->assertDatabaseHas('media_assets', [
            'id' => $media->id,
            'website_id' => $this->website->id,
            'title' => 'Nhà máy sản xuất GMP',
            'mime_type' => 'image/webp',
        ]);

        $this->assertStringContainsString('quy trình sản xuất dược phẩm', $media->alt_text);
        $this->assertTrue(Storage::disk('public')->exists($media->file_path));
        $this->assertTrue(Storage::disk('public')->exists($media->thumbnail_path));
    }

    public function test_it_deduplicates_identical_file_upload(): void
    {
        $sourceFile = $this->createFakeImageFile(300, 200, 'duplicate.jpg');

        $media1 = $this->service->storeMedia($sourceFile, [
            'website_id' => $this->website->id,
            'title' => 'Lần 1',
        ]);

        $media2 = $this->service->storeMedia($sourceFile, [
            'website_id' => $this->website->id,
            'title' => 'Lần 2',
        ]);

        $this->assertEquals($media1->id, $media2->id);
        $this->assertEquals(1, MediaAsset::where('sha256', $media1->sha256)->count());
    }

    public function test_it_attaches_media_and_injects_images_into_html_content(): void
    {
        $article = Article::create([
            'website_id' => $this->website->id,
            'created_by' => $this->user->id,
            'primary_keyword' => 'máy dập viên',
            'search_intent' => 'commercial',
            'topic_cluster' => 'thiết bị dược phẩm',
            'workflow_status' => 'needs_review',
        ]);

        $initialHtml = <<<HTML
<p>Giới thiệu về dòng máy dập viên tốc độ cao đạt tiêu chuẩn GMP châu Âu.</p>
<h2>1. Nguyên lý hoạt động của máy dập viên</h2>
<p>Máy sử dụng cối chày đa tầng và hệ thống nạp hạt tự động giúp viên nén đạt tỷ trọng đồng đều.</p>
<h2>2. Ưu điểm nổi bật về công nghệ</h2>
<p>Hệ thống cảm biến đo lực nén tự động ngắt khi phát hiện dị vật.</p>
HTML;

        $version = ArticleVersion::create([
            'article_id' => $article->id,
            'version' => 1,
            'title' => 'Đánh giá máy dập viên thế hệ mới',
            'slug' => 'danh-gia-may-dap-vien-the-he-moi',
            'focus_keyword' => 'máy dập viên',
            'html_content' => $initialHtml,
            'review_status' => 'needs_review',
        ]);

        $img1 = $this->createFakeImageFile(400, 300, 'img1.jpg', 50, 120, 200);
        $img2 = $this->createFakeImageFile(450, 320, 'img2.jpg', 220, 80, 50);

        $media1 = $this->service->storeMedia($img1, [
            'website_id' => $this->website->id,
            'title' => 'Máy dập viên thực tế',
            'focus_keyword' => 'máy dập viên',
        ]);
        $media2 = $this->service->storeMedia($img2, [
            'website_id' => $this->website->id,
            'title' => 'Bảng điều khiển máy',
            'focus_keyword' => 'máy dập viên',
        ]);

        // Gắn vào article version
        $this->service->attachMediaToArticle($version, [$media1->id, $media2->id], $media1->id);

        $version->refresh();
        $this->assertCount(2, $version->mediaAssets);
        $this->assertEquals($media1->id, $version->featuredMediaAsset->id);

        // Chèn ảnh vào HTML
        $injectedHtml = $this->service->injectImagesIntoHtml($version);

        $this->assertStringContainsString('<figure class="article-image-block">', $injectedHtml);
        $this->assertStringContainsString('loading="lazy"', $injectedHtml);
        $this->assertStringContainsString('alt="Hình ảnh máy dập viên - Máy dập viên thực tế"', $injectedHtml);
    }

    public function test_it_imports_image_safely_from_url(): void
    {
        $fakeImgData = file_get_contents($this->createFakeImageFile(200, 200, 'remote.jpg'));

        $mockHandler = new MockHandler([
            new Response(200, ['Content-Type' => 'image/jpeg'], $fakeImgData),
        ]);
        $handlerStack = HandlerStack::create($mockHandler);
        $mockHttpClient = new HttpClient(['handler' => $handlerStack]);

        $customService = new MediaService(
            app(MediaSecurityValidator::class),
            app(ImageOptimizer::class),
            app(UrlSafetyService::class),
            $mockHttpClient
        );

        $media = $customService->importFromUrl('https://addp.vn/images/sample.jpg', [
            'website_id' => $this->website->id,
            'title' => 'Ảnh tải từ domain công ty',
        ], ['addp.vn']);

        $this->assertInstanceOf(MediaAsset::class, $media);
        $this->assertEquals('Ảnh tải từ domain công ty', $media->title);
        $this->assertTrue(Storage::disk('public')->exists($media->file_path));
    }
}
