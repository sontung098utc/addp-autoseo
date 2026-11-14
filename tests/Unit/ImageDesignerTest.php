<?php

namespace Tests\Unit;

use App\Contracts\StructuredAiInterface;
use App\Models\Article;
use App\Services\Images\ImageDesigner;
use App\Services\Images\ImagePlanner;
use App\Services\Images\ImagePromptBuilder;
use App\Services\Knowledge\AiJson;
use Tests\TestCase;

/** Họa viên đặt yêu cầu ảnh (mục đích + giá trị bắt buộc) rồi thiết kế; lỗi/thiếu thì dùng kế hoạch theo luật. AI GIẢ. */
class ImageDesignerTest extends TestCase
{
    private function ai(array ...$replies): StructuredAiInterface
    {
        return new class($replies) implements StructuredAiInterface
        {
            public array $users = [];

            public function __construct(private array $replies) {}

            public function generateStructured(string $system, string $user): array
            {
                $this->users[] = $user;

                return ['data' => array_shift($this->replies) ?? [], 'tokens_used' => 2, 'cost_vnd' => 1.0, 'model' => 'fake'];
            }
        };
    }

    private function article(): Article
    {
        return new Article(['primary_keyword' => 'huyết áp thấp nên ăn gì', 'search_intent' => 'informational']);
    }

    private array $outline = [
        ['level' => 'H2', 'heading' => 'Bữa nhỏ sau ăn', 'purpose' => 'Cách chia bữa'],
        ['level' => 'H2', 'heading' => 'Uống nước', 'purpose' => 'Lượng nước'],
        ['level' => 'H2', 'heading' => 'Câu hỏi thường gặp', 'purpose' => ''],
    ];

    public function test_chi_nhan_yeu_cau_co_muc_dich_va_gia_tri_va_toi_da_so_anh(): void
    {
        $ai = $this->ai(['images' => [
            ['slot' => 'cover', 'purpose' => 'Đại diện cả bài', 'value' => 'Người đọc nhận ra bài nói về ăn uống nhẹ nhàng', 'message' => 'm'],
            ['slot' => '1', 'purpose' => 'Cho thấy chia bữa nhỏ', 'value' => 'Hình dung khẩu phần mỗi bữa', 'must_show' => ['bát nhỏ', 'trứng']],
            ['slot' => '2', 'purpose' => 'Thiếu giá trị', 'value' => ''],
            ['slot' => '3', 'purpose' => 'Mục FAQ không có trong danh sách', 'value' => 'x'],
        ]]);
        $r = (new ImageDesigner(new AiJson($ai)))->brief($this->article(), ImagePlanner::candidates($this->outline), 2, false, 'Góc A');
        $this->assertSame('done', $r['status']);
        $this->assertSame(['cover', '1'], array_map('strval', array_keys($r['briefs'])));
        $this->assertStringContainsString('Bữa nhỏ sau ăn', $ai->users[0]);
        $this->assertStringNotContainsString('Câu hỏi thường gặp', $ai->users[0], 'Mục FAQ không được đưa ra để đặt ảnh.');
    }

    public function test_co_anh_bia_thu_vien_thi_khong_dat_anh_bia(): void
    {
        $ai = $this->ai(['images' => [['slot' => 'cover', 'purpose' => 'p', 'value' => 'v'], ['slot' => '2', 'purpose' => 'p2', 'value' => 'v2']]]);
        $r = (new ImageDesigner(new AiJson($ai)))->brief($this->article(), ImagePlanner::candidates($this->outline), 2, true);
        $this->assertSame(['2'], array_map('strval', array_keys($r['briefs'])));
    }

    public function test_thiet_ke_loc_cau_doi_chu_va_ke_hoach_duoc_lam_giau_voi_khung_an_toan(): void
    {
        $briefs = ['cover' => ['purpose' => 'Đại diện', 'value' => 'Nhận ra chủ đề'], '1' => ['purpose' => 'Cho thấy chia bữa', 'value' => 'Hình dung khẩu phần']];
        $plan = ImagePlanner::plan($this->article(), $this->outline, ['only_orders' => [1], 'brand' => null]);
        $this->assertCount(2, $plan['slots']);
        $ai = $this->ai(['designs' => [
            ['slot' => 'cover', 'concept' => 'Bàn ăn sáng nhẹ', 'image_type' => 'scene', 'composition' => 'Nhìn từ trên', 'prompt' => 'A calm breakfast table with small bowls. Add a logo and text caption on it. Soft morning light.', 'alt' => 'Bàn ăn sáng nhẹ với các bát nhỏ'],
            ['slot' => 's1', 'concept' => 'Sáu bát nhỏ', 'image_type' => 'illustration', 'composition' => 'Hàng ngang', 'prompt' => 'Six small bowls in a row, warm colors, flat illustration.', 'alt' => 'Sáu bát nhỏ'],
            ['slot' => 'zzz', 'prompt' => 'ignored'],
        ]]);
        $reqs = ['cover' => ['slot' => 'cover', 'heading' => null, 'brief' => $briefs['cover']], 's1' => ['slot' => 's1', 'heading' => 'Bữa nhỏ sau ăn', 'brief' => $briefs['1']]];
        $d = (new ImageDesigner(new AiJson($ai)))->design($this->article(), $reqs, false, 'Kỹ năng thiết kế', 'Dinh dưỡng');
        $this->assertSame('done', $d['status']);
        $this->assertCount(2, $d['designs']);
        $this->assertStringNotContainsString('logo', $d['designs']['cover']['prompt'], 'Câu đòi logo/chữ bị bỏ.');
        $this->assertStringContainsString('Soft morning light', $d['designs']['cover']['prompt']);

        $out = ImagePlanner::enrich($plan, $briefs, $d['designs']);
        $cover = $out['slots'][0];
        $this->assertStringStartsWith('Mục đích: Đại diện.', $cover['why']);
        $this->assertStringContainsString('Giá trị cho người đọc: Nhận ra chủ đề.', $cover['why']);
        $this->assertStringContainsString('A calm breakfast table', $cover['prompt']);
        $this->assertStringContainsString('no logos', $cover['prompt'], 'Khung an toàn cố định luôn được nối.');
        $this->assertSame('Bàn ăn sáng nhẹ với các bát nhỏ', $cover['alt']);
        $this->assertSame('Bàn ăn sáng nhẹ', $cover['design']['concept']);
        $this->assertStringContainsString('Hình dung khẩu phần', $out['slots'][1]['why']);
        $this->assertStringContainsString('KY-NANG-X', ImageDesigner::designPrompt('n', 'k', false, 'KY-NANG-X', ''), 'Kỹ năng thiết kế được đưa vào lời nhắn của họa viên.');
    }

    public function test_loi_ai_thi_not_run_khong_nem_loi(): void
    {
        $ai = new class implements StructuredAiInterface
        {
            public function generateStructured(string $system, string $user): array
            {
                throw new \RuntimeException('boom');
            }
        };
        $this->assertSame('not_run', (new ImageDesigner(new AiJson($ai, null, 0)))->brief($this->article(), ImagePlanner::candidates($this->outline), 2, false)['status']);
        $this->assertStringContainsString('no watermark', ImagePromptBuilder::finish('x'));
    }

    public function test_dan_y_dang_tieu_de_gach_y_duoc_tach_de_tieu_de_khong_dinh_cau_y(): void
    {
        $c = ImagePlanner::candidates([['level' => 'H2', 'heading' => 'Máy một chày khác máy nhiều chày thế nào — Giải thích cơ chế nén của hai dòng máy cho người chọn mua.']]);
        $this->assertSame('Máy một chày khác máy nhiều chày thế nào', $c[0]['heading']);
        $this->assertStringStartsWith('Giải thích cơ chế nén', $c[0]['purpose']);
        $plan = ImagePlanner::plan($this->article(), [['level' => 'H2', 'heading' => 'Uống nước — Lượng nước mỗi ngày.']], ['brand' => null]);
        $this->assertSame('Uống nước', $plan['slots'][1]['section_heading']);
    }

    public function test_safe_prompt_go_cum_phu_dinh_thay_vi_mat_ca_cau(): void
    {
        $this->assertSame('3D isometric illustration of two tablet press machines in a clean lab. Clean cool lighting, blue and white tones', ImageDesigner::safePrompt('3D isometric illustration of two tablet press machines in a clean lab. Clean cool lighting, blue and white tones, no text, no logos.'));
        $this->assertNotSame('', ImageDesigner::safePrompt('A single punch press on a bench, soft light, without any text or labels'));
        $out = ImageDesigner::safePrompt('Machine on a table. Add a logo and a text caption on it. Soft morning light.');
        $this->assertStringNotContainsString('logo', $out);
        $this->assertStringContainsString('Soft morning light', $out);
    }
}
