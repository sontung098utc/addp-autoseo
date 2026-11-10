<?php

namespace Tests\Feature\Knowledge;

use App\Models\KnowledgeItem;
use App\Models\User;
use App\Models\Website;
use App\Services\Knowledge\KnowledgeConflicts;
use App\Services\Knowledge\KnowledgeDedupe;
use App\Services\Knowledge\KnowledgeError;
use App\Services\Knowledge\KnowledgeFreshness;
use App\Services\Knowledge\KnowledgeIngest;
use App\Services\Knowledge\KnowledgeRetriever;
use App\Services\Knowledge\KnowledgeReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** AG-S20: lõi kho kiến thức — tách tất định, trùng/mâu thuẫn/cũ, truy xuất, duyệt, phiên bản, quyền. */
class KnowledgeCoreTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'approver'): User
    {
        return User::create(['name' => $role, 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => $role, 'is_active' => true]);
    }

    private function site(string $name = 'A'): Website
    {
        return Website::create(['name' => $name, 'cms_type' => 'wordpress', 'base_url' => 'https://'.uniqid().'.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
    }

    private function item(array $a = []): KnowledgeItem
    {
        $a += ['type' => 'fact', 'title' => 'Học bổng toàn phần', 'body' => 'Học bổng toàn phần miễn 100% học phí cho sinh viên xuất sắc.', 'status' => 'approved', 'version' => 1];
        $a['content_hash'] ??= KnowledgeDedupe::hash($a['title'], $a['body']);

        return KnowledgeItem::create($a);
    }

    public function test_tach_doan_tat_dinh_theo_doan_trong_va_tieu_de(): void
    {
        $text = "# Bảng giá\nGói Cơ bản có giá 8.000.000 đồng mỗi năm.\n\nChính sách hoàn tiền\nHoàn tiền trong 7 ngày nếu chưa sử dụng dịch vụ.\n\nNgắn\n\nĐây là một đoạn không có tiêu đề. Câu thứ hai của đoạn.";
        $ingest = new KnowledgeIngest();
        $a = $ingest->parse($text);
        $this->assertCount(3, $a);
        $this->assertSame('Bảng giá', $a[0]['title']);
        $this->assertSame('Chính sách hoàn tiền', $a[1]['title']);
        $this->assertSame('Đây là một đoạn không có tiêu đề.', $a[2]['title']);
        $this->assertSame($a, $ingest->parse($text), 'tất định');

        $src = $ingest->paste('Tài liệu', $text, null, $this->user());
        $r = $ingest->split($src);
        $this->assertSame(3, $r['created']);
        $this->assertSame(['proposed'], KnowledgeItem::pluck('status')->unique()->all());
        $this->assertSame('split', $src->refresh()->status);
        // tách lại: trùng y hệt được bỏ qua
        $r2 = $ingest->split($src);
        $this->assertSame(0, $r2['created']);
        $this->assertSame(3, $r2['duplicates']);
    }

    public function test_trung_gan_giong_va_y_het(): void
    {
        $a = $this->item();
        $same = new KnowledgeItem(['title' => 'x', 'body' => 'HỌC BỔNG toàn phần, miễn 100% học phí cho sinh viên xuất sắc!']);
        $this->assertTrue((new KnowledgeDedupe())->find($same)['exact']->contains('id', $a->id));
        $near = new KnowledgeItem(['title' => 'x', 'body' => 'Học bổng toàn phần miễn 100% học phí cho sinh viên xuất sắc nhất.']);
        $f = (new KnowledgeDedupe())->find($near);
        $this->assertTrue($f['exact']->isEmpty());
        $this->assertCount(1, $f['similar']);
        $diff = new KnowledgeItem(['title' => 'x', 'body' => 'Quy trình nhập học gồm ba bước và cần giấy tờ.']);
        $this->assertFalse((new KnowledgeDedupe())->isDuplicate($diff));
    }

    public function test_mau_thuan_cung_thuc_the_truong_khac_gia_tri(): void
    {
        $s = $this->site();
        $a = $this->item(['website_id' => $s->id, 'entity' => 'Gói Cơ bản', 'field' => 'giá', 'value' => '8000000', 'body' => 'Giá gói cơ bản 8 triệu.']);
        $b = $this->item(['website_id' => $s->id, 'status' => 'proposed', 'entity' => 'gói cơ bản', 'field' => 'Giá', 'value' => '9000000', 'body' => 'Giá gói cơ bản 9 triệu.']);
        $same = $this->item(['website_id' => $s->id, 'status' => 'proposed', 'entity' => 'Gói Cơ bản', 'field' => 'giá', 'value' => '8000000', 'body' => 'Gói cơ bản giá 8 triệu đồng.']);
        $other = $this->item(['website_id' => $this->site('B')->id, 'entity' => 'Gói Cơ bản', 'field' => 'giá', 'value' => '1', 'body' => 'Website khác.']);
        $c = new KnowledgeConflicts();
        $this->assertEqualsCanonicalizing([$a->id, $same->id], $c->for($b)->pluck('id')->all());
        $this->assertTrue($c->for($same)->contains('id', $b->id));
        $this->assertFalse($c->for($a)->contains('id', $other->id), 'khác website không mâu thuẫn');
        $general = $this->item(['website_id' => null, 'entity' => 'Gói Cơ bản', 'field' => 'giá', 'value' => '7', 'body' => 'Chung.']);
        $this->assertTrue($c->for($a)->contains('id', $general->id), 'mục chung mâu thuẫn với mục website');
    }

    public function test_cu_het_han_va_chua_dung(): void
    {
        $exp = $this->item(['valid_until' => now()->subDay()->toDateString(), 'title' => 'Hết hạn', 'body' => 'Nội dung hết hạn dài đủ.']);
        $ok = $this->item(['valid_until' => now()->addDay()->toDateString(), 'title' => 'Còn hạn', 'body' => 'Nội dung còn hạn dài đủ.']);
        $old = $this->item(['approved_at' => now()->subDays(400), 'title' => 'Lâu', 'body' => 'Nội dung lâu không dùng.']);
        $f = new KnowledgeFreshness();
        $this->assertSame([$exp->id], $f->expired()->pluck('id')->all());
        $this->assertTrue($f->unused()->contains('id', $old->id));
        $this->assertSame(1, $f->markExpired());
        $this->assertSame('outdated', $exp->refresh()->status);
    }

    public function test_retriever_chi_tra_atom_da_duyet_con_hieu_luc_va_uu_tien_website(): void
    {
        $s = $this->site();
        $own = $this->item(['website_id' => $s->id, 'title' => 'Học bổng riêng', 'body' => 'Học bổng toàn phần của trường.']);
        $gen = $this->item(['website_id' => null, 'title' => 'Học bổng chung', 'body' => 'Học bổng toàn phần chung.']);
        $this->item(['website_id' => $s->id, 'status' => 'proposed', 'title' => 'Học bổng đề xuất', 'body' => 'Học bổng toàn phần nháp.']);
        $this->item(['website_id' => $s->id, 'status' => 'rejected', 'title' => 'Học bổng loại', 'body' => 'Học bổng toàn phần loại.']);
        $this->item(['website_id' => $s->id, 'status' => 'outdated', 'title' => 'Học bổng cũ', 'body' => 'Học bổng toàn phần cũ.']);
        $this->item(['website_id' => $s->id, 'valid_until' => now()->subDay()->toDateString(), 'title' => 'Học bổng hết hạn', 'body' => 'Học bổng toàn phần hết hạn.']);
        $this->item(['website_id' => $this->site('B')->id, 'title' => 'Học bổng site khác', 'body' => 'Học bổng toàn phần site khác.']);
        $deleted = $this->item(['website_id' => $s->id, 'title' => 'Học bổng xóa', 'body' => 'Học bổng toàn phần đã xóa.']);
        $deleted->delete();

        $r = (new KnowledgeRetriever())->search($s, 'học bổng toàn phần');
        $this->assertSame([$own->id, $gen->id], array_column($r, 'id'));
        $this->assertSame('website', $r[0]['scope']);
        $this->assertSame(1, $r[0]['version']);
        $this->assertArrayHasKey('source', $r[0]);
        $this->assertSame([], (new KnowledgeRetriever())->search($s, 'zzz không liên quan'));
        $this->assertCount(1, (new KnowledgeRetriever())->search($s, 'học bổng toàn phần', 1));
    }

    public function test_retriever_khong_bi_like_wildcard(): void
    {
        $s = $this->site();
        $this->item(['website_id' => $s->id, 'title' => 'Bình thường', 'body' => 'Nội dung bình thường.']);
        $this->assertSame([], (new KnowledgeRetriever())->search($s, '%%% ___'));
    }

    /**
     * Hồi quy MariaDB: `escape '\'` là lỗi cú pháp trên MySQL/MariaDB (SQLite không phát hiện). Gọi thẳng like() với ký tự đặc biệt
     * (bộ tách từ bình thường đã loại chúng nên test search() không chạm tới) để câu lệnh thực sự được chạy trên cả hai CSDL.
     */
    public function test_like_thoat_ky_tu_dac_biet_va_chay_duoc_tren_moi_csdl(): void
    {
        $s = $this->site();
        $pct = $this->item(['website_id' => $s->id, 'title' => 'Giảm giá', 'body' => 'Giảm 100% học phí kỳ đầu.']);
        $this->item(['website_id' => $s->id, 'title' => 'Giảm giá khác', 'body' => 'Giảm 1000 đồng mỗi buổi học.']);
        $und = $this->item(['website_id' => $s->id, 'title' => 'Mã', 'body' => 'Dùng mã ab_cd khi thanh toán.']);
        $this->item(['website_id' => $s->id, 'title' => 'Mã khác', 'body' => 'Dùng mã abXcd khi thanh toán.']);
        $bang = $this->item(['website_id' => $s->id, 'title' => 'Lưu ý', 'body' => 'Chú ý!đặc biệt về lịch học.']);

        $like = new \ReflectionMethod(KnowledgeRetriever::class, 'like');
        $run = fn (string $term) => collect($like->invoke(new KnowledgeRetriever(), $s, [$term], $term, null))->pluck('id')->all();

        $this->assertSame([$pct->id], $run('100%'), '% là ký tự thường, không phải wildcard');
        $this->assertSame([$und->id], $run('ab_cd'), '_ là ký tự thường, không khớp "abXcd"');
        $this->assertSame([$bang->id], $run('!đặc'), 'ký tự thoát "!" cũng được thoát');
        $this->assertSame([], $run('%%%'), 'chỉ toàn wildcard thì không khớp gì');
    }

    public function test_duyet_bi_chan_khi_con_mau_thuan_va_giai_quyet_bang_chon_ban_dung(): void
    {
        $by = $this->user();
        $a = $this->item(['entity' => 'Gói', 'field' => 'giá', 'value' => '1', 'body' => 'Giá một.']);
        $b = $this->item(['status' => 'proposed', 'entity' => 'Gói', 'field' => 'giá', 'value' => '2', 'body' => 'Giá hai.']);
        $r = new KnowledgeReview();
        try {
            $r->approve($b, $by);
            $this->fail('phải bị chặn');
        } catch (KnowledgeError $e) {
            $this->assertStringContainsString('mâu thuẫn', $e->getMessage());
        }
        $this->assertSame('proposed', $b->refresh()->status);
        $r->resolveConflict($b, $by);
        $this->assertSame('approved', $b->refresh()->status);
        $this->assertSame('outdated', $a->refresh()->status);
        $this->assertSame($by->id, $b->approved_by);
    }

    public function test_duyet_ca_nguon_bo_qua_muc_mau_thuan(): void
    {
        $by = $this->user();
        $ing = new KnowledgeIngest();
        $this->item(['entity' => 'Gói', 'field' => 'giá', 'value' => '1', 'body' => 'Giá một đồng.']);
        $src = $ing->paste('T', "Mục thứ nhất\nNội dung thứ nhất đủ dài.\n\nMục thứ hai\nNội dung thứ hai đủ dài.", null, $by);
        $ing->split($src);
        $first = KnowledgeItem::where('source_id', $src->id)->first();
        $first->update(['entity' => 'Gói', 'field' => 'giá', 'value' => '2']);
        $res = (new KnowledgeReview())->approveSource($src->id, $by);
        $this->assertSame(['approved' => 1, 'blocked' => 1], $res);
    }

    public function test_sua_muc_da_duyet_tao_phien_ban_moi_va_ve_de_xuat(): void
    {
        $by = $this->user();
        $i = $this->item(['approved_by' => $by->id, 'approved_at' => now()]);
        $r = new KnowledgeReview();
        $r->update($i, ['tags' => 'a, b', 'type' => 'policy'], $by);
        $i->refresh();
        $this->assertSame(1, $i->version, 'đổi thẻ/loại không tạo phiên bản');
        $this->assertSame('approved', $i->status);

        $r->update($i, ['body' => 'Nội dung đã sửa khác hẳn.'], $by);
        $i->refresh();
        $this->assertSame(2, $i->version);
        $this->assertSame('proposed', $i->status);
        $this->assertNull($i->approved_by);
        $this->assertSame([2, 1], $i->versions->pluck('version')->all());
        $this->assertSame('Học bổng toàn phần miễn 100% học phí cho sinh viên xuất sắc.', $i->versions->last()->body, 'phiên bản cũ được giữ');
        $this->assertSame([], (new KnowledgeRetriever())->search($this->site(), 'học bổng toàn phần'), 'về đề xuất thì AI không dùng');
    }

    public function test_quyen_chi_admin_approver_duoc_duyet(): void
    {
        $i = $this->item(['status' => 'proposed']);
        foreach (['writer', 'viewer'] as $role) {
            try {
                (new KnowledgeReview())->approve($i, $this->user($role));
                $this->fail("$role không được duyệt");
            } catch (KnowledgeError) {
            }
        }
        (new KnowledgeReview())->approve($i, $this->user('admin'));
        $this->assertSame('approved', $i->refresh()->status);
    }

    public function test_gop_loai_muc_trung(): void
    {
        $by = $this->user();
        $keep = $this->item(['tags' => ['x']]);
        $dup = $this->item(['title' => 'Trùng', 'body' => 'Bản trùng khác.', 'tags' => ['y']]);
        (new KnowledgeReview())->merge($keep, $dup, $by);
        $this->assertSame('rejected', $dup->refresh()->status);
        $this->assertEqualsCanonicalizing(['x', 'y'], $keep->refresh()->tags);
    }
}
