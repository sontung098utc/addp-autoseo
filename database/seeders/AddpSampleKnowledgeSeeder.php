<?php

namespace Database\Seeders;

use App\Models\KnowledgeGap;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeSource;
use App\Models\KnowledgeTopic;
use App\Models\Website;
use App\Services\Knowledge\KnowledgeIngest;
use Illuminate\Database\Seeder;

/**
 * Kho kiến thức MẪU cho website ADDP. Chỉ dùng thông tin có thật trong tài liệu/dự án (ghi nguồn từng mục);
 * mục nào chưa có thông tin thật thì là "khoảng trống kiến thức" cần ADDP điền, KHÔNG bịa sản phẩm, giá, giấy phép.
 * Mọi mục ở trạng thái "Đề xuất" nên AI KHÔNG dùng cho tới khi người có quyền xem và duyệt. Chạy lại không tạo trùng.
 *
 * Chạy: php artisan db:seed --class=AddpSampleKnowledgeSeeder
 */
class AddpSampleKnowledgeSeeder extends Seeder
{
    public const SOURCE_TITLE = 'Mẫu ADDP: tổng hợp từ tài liệu dự án';

    public function run(): void
    {
        // Website ở trạng thái "pending" (chưa kết nối): không chiến dịch nào giao việc cho website này.
        $site = Website::firstOrCreate(
            ['base_url' => 'https://duocaddp.com', 'store_scope' => 'default'],
            ['name' => 'Công ty Dược ADDP', 'cms_type' => 'wordpress', 'encrypted_credentials' => [], 'status' => 'pending'],
        );

        $source = KnowledgeSource::firstOrCreate(['website_id' => $site->id, 'title' => self::SOURCE_TITLE], [
            'kind' => 'manual', 'status' => 'split', 'content_hash' => hash('sha256', self::SOURCE_TITLE),
            'raw_text' => "Nguồn của các mục mẫu:\n"
                ."1) Dữ liệu thử nghiệm của dự án addp-autoseo (tên 'Công ty Dược ADDP', website duocaddp.com).\n"
                ."2) Tệp 'Kế hoạch phần mềm Auto SEO.xlsx' (phạm vi: website công ty, tiếng Việt; kết nối WordPress và Magento 2).\n"
                ."3) Thiết kế đội AI của dự án (docs/seo-agent): bậc tin cậy T1, hạng rủi ro chủ đề, trần bài mỗi ngày.\n"
                ."4) Kiến thức phổ biến về quảng cáo thuốc/thực phẩm tại Việt Nam: CHƯA đối chiếu văn bản gốc.\n"
                .'Không có thông tin về sản phẩm, giá, giấy phép hay chính sách bán hàng của ADDP trong các nguồn trên; những phần đó nằm ở danh sách khoảng trống.',
        ]);

        $topics = [];
        foreach (['Giới thiệu công ty', 'Kênh nội dung và website', 'Quy trình nội dung', 'Quy định nội dung y tế'] as $name) {
            $topics[$name] = KnowledgeTopic::firstOrCreate(['website_id' => $site->id, 'name' => $name, 'parent_id' => null])->id;
        }

        $ingest = new KnowledgeIngest();
        foreach ($this->items() as $i) {
            if (KnowledgeItem::query()->where('website_id', $site->id)->where('title', $i['title'])->exists()) {
                continue;
            }
            $item = $ingest->manual(array_merge($i, ['topic_id' => $topics[$i['topic']], 'tags' => array_merge(['mau'], $i['tags'] ?? [])]), $site, null);
            $item->update(['source_id' => $source->id, 'created_by' => 'sample', 'confidence' => $i['confidence'], 'source_span' => $i['source']]);
        }

        foreach ($this->gaps() as $q) {
            KnowledgeGap::firstOrCreate(['website_id' => $site->id, 'query' => $q, 'article_id' => null], ['count' => 1]);
        }
    }

    /** @return list<array<string, mixed>> */
    private function items(): array
    {
        $legal = 'Kiến thức phổ biến, CHƯA đối chiếu văn bản gốc (Luật Dược, Luật Quảng cáo và văn bản hướng dẫn hiện hành). Cần pháp chế ADDP xác nhận trước khi duyệt mục này.';

        return [
            ['topic' => 'Giới thiệu công ty', 'type' => 'fact', 'title' => 'Tên công ty và website', 'entity' => 'Công ty Dược ADDP', 'field' => 'website', 'value' => 'duocaddp.com', 'confidence' => 40,
                'source' => 'Dữ liệu thử nghiệm của dự án',
                'body' => 'Tên được dùng trong dữ liệu thử nghiệm của dự án: Công ty Dược ADDP; website: duocaddp.com. Cần ADDP xác nhận tên pháp lý đầy đủ, địa chỉ và thông tin liên hệ chính thức.'],
            ['topic' => 'Kênh nội dung và website', 'type' => 'fact', 'title' => 'Các kênh đăng nội dung dự kiến', 'confidence' => 70,
                'source' => 'Kế hoạch phần mềm Auto SEO.xlsx (sheet 1.1 và 3_Ket_noi_website)',
                'body' => 'Phần mềm Auto SEO phục vụ website của công ty trước, bằng tiếng Việt. Dự kiến hai kênh: blog trên WordPress (đăng qua REST API) và website thương mại điện tử trên Magento 2 (đăng qua module cầu nối blog riêng). Chưa có thông tin về tên miền chính thức của từng kênh.'],
            ['topic' => 'Quy trình nội dung', 'type' => 'process', 'title' => 'Bài do AI viết chỉ là bản nháp', 'confidence' => 90,
                'source' => 'Thiết kế đội AI (APPLY_PLAN_V2, bậc tin cậy T1)',
                'body' => 'Ở bậc tin cậy hiện tại (T1), đội AI chỉ tạo bản nháp chờ người duyệt, không tự xuất bản. Chế độ Tự chủ (tự đăng) bị khóa cho tới khi ADDP có văn bản chính sách và website hỗ trợ gỡ bài.'],
            ['topic' => 'Quy trình nội dung', 'type' => 'process', 'title' => 'Bài sức khỏe, thuốc, tài chính cần người có chuyên môn duyệt', 'confidence' => 90,
                'source' => 'Thiết kế đội AI (hạng rủi ro chủ đề, resources/playbooks/risk/topics.php)',
                'body' => 'Hệ thống xếp các chủ đề sức khỏe, y tế, thuốc, điều trị, tài chính, pháp lý vào hạng rủi ro cao. Bài thuộc nhóm này luôn được giữ để người có chuyên môn duyệt, không đủ điều kiện tự đăng.'],
            ['topic' => 'Quy trình nội dung', 'type' => 'policy', 'title' => 'Giờ đăng theo giờ Việt Nam và trần bài mỗi ngày', 'confidence' => 90,
                'source' => 'Thiết kế chiến dịch của dự án',
                'body' => 'Lịch hiển thị theo giờ Việt Nam (UTC+7), hệ thống lưu giờ UTC. Mỗi chiến dịch có giới hạn số bài mỗi ngày (trần cứng 50, nên đặt thấp hơn nhiều) để tránh tạo nội dung hàng loạt.'],
            ['topic' => 'Quy định nội dung y tế', 'type' => 'policy', 'title' => 'Không quảng cáo thuốc kê đơn cho công chúng', 'confidence' => 40, 'tags' => ['can-xac-nhan'],
                'source' => 'Kiến thức phổ biến, chưa đối chiếu văn bản gốc',
                'body' => 'Theo hiểu biết chung về quy định quảng cáo thuốc tại Việt Nam, thuốc kê đơn không được quảng cáo cho công chúng và nội dung quảng cáo thuốc phải phù hợp với nội dung được cơ quan quản lý cho phép. '.$legal],
            ['topic' => 'Quy định nội dung y tế', 'type' => 'policy', 'title' => 'Không nói thực phẩm bảo vệ sức khỏe là thuốc chữa bệnh', 'confidence' => 40, 'tags' => ['can-xac-nhan'],
                'source' => 'Kiến thức phổ biến, chưa đối chiếu văn bản gốc',
                'body' => 'Theo hiểu biết chung, thực phẩm bảo vệ sức khỏe không được giới thiệu như thuốc hay như có tác dụng chữa bệnh. '.$legal],
            ['topic' => 'Quy định nội dung y tế', 'type' => 'messaging', 'title' => 'Tránh cam kết kết quả tuyệt đối', 'confidence' => 80,
                'source' => 'Danh sách claim nhạy cảm của hệ thống (resources/playbooks/risk/topics.php)',
                'body' => 'Không dùng các cách nói như "cam kết", "đảm bảo 100%", "chắc chắn khỏi", "hiệu quả tuyệt đối", "không tác dụng phụ". Hệ thống coi các cụm này là claim nhạy cảm, nâng hạng rủi ro của bài và không cho đủ điều kiện tự đăng.'],
        ];
    }

    /** @return list<string> */
    private function gaps(): array
    {
        return [
            'Danh mục sản phẩm và công dụng được phép nêu',
            'Giấy phép, chứng nhận và số đăng ký sản phẩm',
            'Địa chỉ, hotline và giờ làm việc',
            'Chính sách đổi trả, bảo hành, vận chuyển',
            'Bảng giá và chương trình khuyến mãi hiện hành',
            'Khách hàng mục tiêu và giọng thương hiệu',
        ];
    }
}
