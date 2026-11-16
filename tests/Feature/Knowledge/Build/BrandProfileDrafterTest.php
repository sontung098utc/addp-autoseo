<?php

namespace Tests\Feature\Knowledge\Build;

use App\Contracts\StructuredAiInterface;
use App\Models\BrandProfile;
use App\Models\KnowledgeSource;
use App\Models\User;
use App\Models\Website;
use App\Services\Knowledge\Build\BrandProfileDrafter;
use App\Services\Knowledge\KnowledgeError;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** Hồ sơ doanh nghiệp tự điền: AI GIẢ, không mạng. */
class BrandProfileDrafterTest extends TestCase
{
    use RefreshDatabase;

    private Website $site;

    private const TEXT = 'Ánh Dương là công ty tư vấn du học Úc và Canada tại Hà Nội. Chúng tôi phục vụ học sinh THPT và phụ huynh muốn cho con đi học nước ngoài. Đội ngũ tư vấn có hơn 10 năm kinh nghiệm hồ sơ visa. Cam kết hồ sơ minh bạch từng bước. Gói Cơ bản có giá 8.000.000 đồng mỗi năm, thông tin liên hệ ở cuối trang này.';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->site = Website::create(['name' => 'Ánh Dương', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        KnowledgeSource::create(['website_id' => $this->site->id, 'kind' => 'url', 'title' => 'Giới thiệu', 'url' => 'https://a.vn/gioi-thieu', 'raw_text' => self::TEXT, 'content_hash' => 'h1', 'status' => 'new', 'origin' => 'own_site']);
    }

    private function user(): User
    {
        return User::create(['name' => 'ap', 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => 'approver', 'is_active' => true]);
    }

    private function ai(array $data): object
    {
        return new class($data) implements StructuredAiInterface
        {
            public int $calls = 0;

            public function __construct(private array $data) {}

            public function generateStructured(string $system, string $user): array
            {
                $this->calls++;

                return ['data' => $this->data, 'tokens_used' => 50, 'cost_vnd' => 7.0, 'model' => 'fake'];
            }
        };
    }

    private function good(): array
    {
        return [
            'business_field' => 'Tư vấn du học Úc và Canada',
            'audience' => 'Học sinh THPT và phụ huynh',
            'voice' => 'than_thien',
            'key_messages' => ['Hồ sơ minh bạch từng bước', 'Bịa: giảm 50% học phí'],
            'allowed_claims' => ['Đội ngũ tư vấn có hơn 10 năm kinh nghiệm hồ sơ visa', 'Đỗ visa 100%'],
            'forbidden_terms' => ['bao đậu'],
            'evidence' => [
                'business_field' => 'công ty tư vấn du học Úc và Canada tại Hà Nội',
                'audience' => 'học sinh THPT và phụ huynh muốn cho con đi học nước ngoài',
                'voice' => 'Chúng tôi phục vụ học sinh THPT và phụ huynh',
                'key_messages' => ['Cam kết hồ sơ minh bạch từng bước', 'giảm 50% học phí cho mọi hồ sơ'],
            ],
        ];
    }

    private function drafter(array $data): BrandProfileDrafter
    {
        return new BrandProfileDrafter($this->ai($data), 'AI giả', false);
    }

    public function test_dien_truong_trong_co_can_cu_va_khong_bia(): void
    {
        $by = $this->user();
        $r = $this->drafter($this->good())->draftFor($this->site, $by);

        $this->assertTrue($r['applied']);
        $p = BrandProfile::where('website_id', $this->site->id)->first();
        $this->assertSame('Tư vấn du học Úc và Canada', $p->business_field);
        $this->assertSame('Học sinh THPT và phụ huynh', $p->audience);
        $this->assertSame('than_thien', $p->voice);
        $this->assertSame(['Hồ sơ minh bạch từng bước'], $p->key_messages, 'thông điệp không có căn cứ bị loại');
        $this->assertSame(['Đội ngũ tư vấn có hơn 10 năm kinh nghiệm hồ sơ visa'], $p->allowed_claims, 'chỉ câu nguyên văn');
        $this->assertEmpty($p->forbidden_terms, 'không tự thêm từ cấm');
        $this->assertEmpty($p->avoid_competitors);
        $this->assertNotNull(BrandProfileDrafter::last($this->site->id));
    }

    public function test_khong_ghi_de_du_lieu_nguoi_nhap(): void
    {
        BrandProfile::create(['website_id' => $this->site->id, 'business_field' => 'Của tôi', 'audience' => 'Khách của tôi']);
        $r = $this->drafter($this->good())->draftFor($this->site, $this->user());

        // chỉ còn 2 trường bắt buộc trống (voice, key_messages) nên không chạy
        $this->assertFalse($r['applied']);
        $p = BrandProfile::first();
        $this->assertSame('Của tôi', $p->business_field);

        BrandProfile::query()->update(['audience' => null]);
        $r = $this->drafter($this->good())->draftFor($this->site, $this->user());
        $this->assertTrue($r['applied']);
        $p = BrandProfile::first();
        $this->assertSame('Của tôi', $p->business_field);
        $this->assertNotContains('business_field', $r['fields']);
        $this->assertSame('Học sinh THPT và phụ huynh', $p->audience);
    }

    public function test_hoan_tac_xoa_truong_da_ghi_va_giu_truong_nguoi_da_sua(): void
    {
        $by = $this->user();
        $this->drafter($this->good())->draftFor($this->site, $by);
        BrandProfile::first()->update(['audience' => 'Người sửa tay']);

        $cleared = (new BrandProfileDrafter)->undo($this->site, $by);

        $p = BrandProfile::first();
        $this->assertNull($p->business_field);
        $this->assertNull($p->voice);
        $this->assertEmpty($p->key_messages);
        $this->assertSame('Người sửa tay', $p->audience);
        $this->assertNotContains('audience', $cleared);
        $this->assertNull(BrandProfileDrafter::last($this->site->id));
        $this->expectException(KnowledgeError::class);
        (new BrandProfileDrafter)->undo($this->site, $by);
    }

    public function test_khong_rut_duoc_gi_thi_bo_qua_co_ly_do(): void
    {
        $r = $this->drafter(['business_field' => 'Bán thép', 'evidence' => ['business_field' => 'câu không có trong trang này']])->draftFor($this->site, $this->user());
        $this->assertFalse($r['applied']);
        $this->assertNotEmpty($r['reason']);
        $this->assertNull(BrandProfile::first());
    }

    public function test_thieu_ai_thi_bo_qua_co_ly_do_va_khong_ghi(): void
    {
        $r = (new BrandProfileDrafter)->draftFor($this->site, $this->user()); // testing: không AI thật, không local
        $this->assertFalse($r['applied']);
        $this->assertStringContainsString('AI', (string) $r['reason']);
        $this->assertNull(BrandProfile::first());
    }

    public function test_khong_co_quyen_thi_khong_ghi(): void
    {
        $w = User::create(['name' => 'w', 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => 'writer', 'is_active' => true]);
        $r = $this->drafter($this->good())->draftFor($this->site, $w);
        $this->assertFalse($r['applied']);
        $this->assertNull(BrandProfile::first());
    }
}
