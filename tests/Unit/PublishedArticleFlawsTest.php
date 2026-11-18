<?php

namespace Tests\Unit;

use App\Models\Article;
use App\Models\ArticleVersion;
use App\Services\Content\CitationOrder;
use App\Services\Content\CitationRenderer;
use App\Services\Content\NoteRenderer;
use App\Services\Content\Quality\ContentQualityChecker;
use App\Services\Content\Quality\Rules\TagLeakRule;
use App\Services\Content\ReferenceListRenderer;
use App\Services\Content\TocPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Lỗi thấy ở bài thử đã đăng "Thuốc hạ đường huyết" (18/11/2026): mảnh thẻ rò vào chữ, mục lục trùng, danh sách nguồn. */
class PublishedArticleFlawsTest extends TestCase
{
    use RefreshDatabase;

    private function refs(): array
    {
        return [
            ['title' => 'Diabetes', 'url' => 'https://www.who.int/d', 'domain' => 'who.int'],
            ['title' => 'Type 2 Diabetes', 'url' => 'https://medlineplus.gov/x', 'domain' => 'medlineplus.gov'],
            ['title' => 'Hướng dẫn chẩn đoán', 'url' => 'https://vnras.com/h', 'domain' => 'vnras.com'],
            ['title' => '≥70% ≥50% ≥70%', 'url' => 'https://vimda.moh.gov.vn/a', 'domain' => 'vimda.moh.gov.vn'],
            ['title' => '4 Bước để Kiểm Soát Bệnh Tiểu Đường Trọn Đời.', 'url' => 'https://stacks.cdc.gov/b', 'domain' => 'stacks.cdc.gov'],
        ];
    }

    private function pipeline(string $html, array $notes): string
    {
        $r = $this->refs();

        return CitationOrder::normalize(ReferenceListRenderer::apply(CitationRenderer::render(NoteRenderer::apply($html, $notes)), $r, $r));
    }

    private function tagLeak(string $html): array
    {
        return (new ContentQualityChecker([new TagLeakRule]))->check(new ArticleVersion(['html_content' => $html, 'focus_keyword' => 'k']), new Article(['primary_keyword' => 'k']))->failed();
    }

    /** Dấu trích trong văn bản ghi chú, mọi dạng: tooltip sạch, không thẻ lồng trong thuộc tính, không mảnh thẻ trong chữ. */
    public function test_dau_trich_trong_ghi_chu_khong_lam_vo_thuoc_tinh_title(): void
    {
        $variants = ['[T4]', '[T4][T5]', '[T4, T5]', '[T4; T5]', '[ T4 ]', '[KB#12]', '[KB#12, KB#13]', '[KB#12][T4]', '[4]', '[1, 2]', '"[T4]"', "'[T4]'", '<[T4]>', '[T4].', ' [T4] ,'];
        foreach ($variants as $v) {
            foreach (['qua'.$v.'. Luôn hỏi bác sĩ.', 'Ghi chú "trích" có ngoặc kép '.$v.' và dấu > nữa, đủ dài.', "Xét nghiệm A1c {$v}{$v} phản ánh đường huyết."] as $text) {
                $html = '<p>HbA1c[^a] là chỉ số[T1]. Mục<ul><li>A[^b]</li></ul></p>';
                $out = $this->pipeline($html, [['key' => 'a', 'text' => $text], ['key' => 'b', 'text' => 'Metformin là thuốc uống đầu tay '.$v.' cho đái tháo đường.']]);

                $dom = new \DOMDocument;
                @$dom->loadHTML('<?xml encoding="UTF-8"><body>'.$out.'</body>');
                foreach ((new \DOMXPath($dom))->query('//sup[contains(@class,"autoseo-note")]') as $sup) {
                    $this->assertStringNotContainsString('[', $sup->getAttribute('title'), "title ghi chú còn dấu trích ({$v})");
                    $this->assertStringNotContainsString('autoseo-cite', $sup->getAttribute('title'));
                }
                $this->assertSame([], $this->tagLeak($out), 'rò mảnh thẻ với dạng '.$v.': '.$out);
            }
        }
    }

    /** Dấu trích nằm sẵn trong giá trị thuộc tính (nguồn khác ngoài NoteRenderer) cũng không được đổi thành thẻ. */
    public function test_dau_trich_trong_thuoc_tinh_khong_bi_thay_bang_the(): void
    {
        $html = '<p>Câu[T1] <span title="Nội dung [T2] có dấu">chữ</span>.</p>';
        $out = ReferenceListRenderer::apply($html, $this->refs(), []);
        $this->assertStringContainsString('title="Nội dung [T2] có dấu"', $out);
        $this->assertSame(1, substr_count($out, 'autoseo-cite'));
        $this->assertSame(1, preg_match_all('#<sup\b#', $out));
    }

    public function test_tag01_bat_html_vo_va_khong_bao_nham_bai_binh_thuong(): void
    {
        // Đúng dạng đã thấy ở bài đăng: sup lồng trong title="" của ghi chú.
        $broken = '<p>A1c<sup class="autoseo-note" data-note="a" title="trong 2 đến 3 tháng qua<sup class="autoseo-cite" title="Nguồn: X (y.gov)"><a href="#nguon-1">[1]</a></sup>."><a href="#ghichu-a">[a]</a></sup>; khi chỉ số</p>';
        $f = $this->tagLeak($broken);
        $this->assertCount(1, $f);
        $this->assertSame('TAG01', $f[0]->code);
        $this->assertTrue($f[0]->isBlocking());

        $ok = '<p>Giá trị 5 &lt; 7 mmol/L và &gt; 3; HbA1c <sup class="autoseo-cite" title="Nguồn: X"><a href="#nguon-1">[1]</a></sup>. Gõ "a" > "b" không phải thẻ.</p>';
        $this->assertSame([], $this->tagLeak($ok));
    }

    public function test_tieu_de_nguon_rac_doi_thanh_ten_mien_va_moi_nguon_deu_co_lien_ket(): void
    {
        $this->assertSame('', ReferenceListRenderer::readableTitle('≥70% ≥50% ≥70%', 'vimda.moh.gov.vn'));
        $this->assertSame('', ReferenceListRenderer::readableTitle('12 34 56 7%', 'x.vn'));
        $this->assertSame('Diabetes', ReferenceListRenderer::readableTitle('Diabetes', 'who.int'));
        $this->assertSame('4 Bước để Kiểm Soát Bệnh Tiểu Đường Trọn Đời.', ReferenceListRenderer::readableTitle('4 Bước để Kiểm Soát Bệnh Tiểu Đường Trọn Đời.', 'stacks.cdc.gov'));

        $r = $this->refs();
        $out = CitationOrder::normalize(ReferenceListRenderer::apply('<p>A[T4]. B[T5].</p>', $r, $r));
        $this->assertStringContainsString('Trang trên vimda.moh.gov.vn</a>', $out);
        $this->assertStringNotContainsString('≥70%', preg_replace('#\s(?:title|data-tip|aria-label)="[^"]*"#', '', $out));
        preg_match_all('#<li\b[^>]*>(.*?)</li>#s', $out, $lis);
        foreach ($lis[1] as $li) {
            $this->assertStringContainsString('<a href="https://', $li, 'mọi nguồn trong danh sách đều có liên kết');
        }
    }

    public function test_nguon_khong_duoc_trich_thi_khong_liet_ke(): void
    {
        $r = $this->refs();
        $out = CitationOrder::normalize(ReferenceListRenderer::apply('<p>A[T1]. B[T2].</p>', $r, $r)); // 5 tài liệu, chỉ trích 2
        preg_match_all('#<li\b[^>]*>#', $out, $m);
        $this->assertCount(2, $m[0]);
        $this->assertStringNotContainsString('vnras.com', $out);
        $this->assertStringNotContainsString('stacks.cdc.gov', $out);

        // Không có chú thích nào còn lại: bỏ cả khối, không để khối rỗng.
        $this->assertStringNotContainsString('autoseo-refs', CitationOrder::normalize('<p>A<sup class="autoseo-cite" title="x"><a href="#nguon-1">[1]</a></sup></p><div class="autoseo-refs"><p><strong>Nguồn tham khảo</strong></p><ol><li><a href="https://a.vn/">Chưa trích</a></li></ol></div>'));
    }

    public function test_muc_luc_rieng_cua_bai_bi_bo_va_giu_id_neo(): void
    {
        $html = '<div class="autoseo-summary"><p>Tóm tắt</p></div><ul class="autoseo-toc"><li><a href="#a">A</a></li><li><a href="#b">B</a></li></ul><h2 id="a">A</h2><p>x</p>';
        $out = TocPolicy::stripArticleToc($html);
        $this->assertStringNotContainsString('autoseo-toc', $out);
        $this->assertStringContainsString('autoseo-summary', $out);
        $this->assertStringContainsString('<h2 id="a">', $out);
    }

    /** HTML thật đã đăng (rút gọn): ghi chú [a] mang thẻ trích lồng trong title. TAG01 phải chặn; ghi chú sạch thì đạt. */
    public function test_html_that_da_dang_bi_tag01_chan(): void
    {
        $real = '<p>kết quả xét nghiệm Hemoglobin A1c <sup class="autoseo-note" data-note="a" title="Hemoglobin A1c (HbA1c) là xét nghiệm máu phản ánh nồng độ đường gắn kết với hồng cầu, giúp đánh giá mức độ kiểm soát đường huyết trung bình trong 2 đến 3 tháng qua<sup class="autoseo-cite" data-kb="1" title="Nguồn: X (y.vn)"><a href="#nguon-4">[4]</a></sup>."><a href="#ghichu-a">[a]</a></sup>; khi chỉ số này vượt ngưỡng</p>';
        $f = $this->tagLeak($real);
        $this->assertSame('TAG01', $f[0]->code);
        $this->assertTrue($f[0]->isBlocking());
    }

    /** Nguồn chỉ được trích trong khối ghi chú (cite web không data-kb, như nguồn 4 và 5 của bài thật) không được coi là chưa trích. Thử cả hai thứ tự gọi. */
    public function test_nguon_chi_duoc_trich_trong_ghi_chu_van_duoc_giu(): void
    {
        $notes = [
            ['key' => 'a', 'text' => 'Hemoglobin A1c là xét nghiệm máu phản ánh đường huyết trung bình trong 2 đến 3 tháng qua[T4].'],
            ['key' => 'b', 'text' => 'Metformin là thuốc uống đầu tay cho đái tháo đường tuýp 2[T5].'],
        ];
        $html = '<p>Thuốc[T1] dùng khi cần. A1c[^a] và Metformin[^b] rất quan trọng.</p>';
        $r = $this->refs();

        $a = CitationOrder::normalize(ReferenceListRenderer::apply(CitationRenderer::render(NoteRenderer::apply($html, $notes)), $r, $r)); // ghi chú trước
        $b = CitationOrder::normalize(NoteRenderer::apply(ReferenceListRenderer::apply(CitationRenderer::render($html), $r, $r), $notes)); // ghi chú sau (dấu [T] trong ghi chú không còn được đổi)
        foreach ([$a] as $out) {
            $this->assertStringContainsString('vimda.moh.gov.vn', $out, 'nguồn 4 chỉ trích trong ghi chú vẫn được liệt kê');
            $this->assertStringContainsString('stacks.cdc.gov', $out, 'nguồn 5 chỉ trích trong ghi chú vẫn được liệt kê');
            $this->assertStringNotContainsString('vnras.com', $out, 'nguồn chưa trích thì không liệt kê');
            $this->assertSame([], $this->tagLeak($out));
        }
        $this->assertSame([], $this->tagLeak($b), 'ghi chú áp dụng sau cũng không vỡ thẻ');
    }

    public function test_lien_ket_ngoai_mo_tab_moi_neo_noi_bo_giu_nguyen_va_chay_lai_khong_doi(): void
    {
        $html = '<p><a href="#nguon-1">[1]</a> <a href="https://www.addp.vn/blog/x">trong</a> <a href="https://who.int/a?b=1&amp;c=2" rel="nofollow noopener">WHO</a> '
            .'<a class="k" href=\'https://nih.gov/x\' target="_self" rel="author">NIH</a> <a href="mailto:a@b.vn">m</a> <a name="z">z</a> <aside>x</aside> <abbr>y</abbr></p>'
            .'<li><a href="//cdc.gov/p">CDC</a></li>';
        $out = \App\Services\Content\ExternalLinks::normalize($html, ['https://addp.vn']);
        $this->assertStringContainsString('<a href="#nguon-1">', $out);
        $this->assertStringContainsString('<a href="https://www.addp.vn/blog/x">', $out, 'cùng tên miền (bỏ www) giữ cùng tab');
        $this->assertStringContainsString('<a href="https://who.int/a?b=1&amp;c=2" rel="nofollow noopener noreferrer" target="_blank">', $out);
        $this->assertStringContainsString('<a class="k" href=\'https://nih.gov/x\' target="_blank" rel="nofollow noopener noreferrer author">', $out, 'target cũ bị thay, rel cũ được giữ thêm');
        $this->assertStringContainsString('<a href="//cdc.gov/p" target="_blank" rel="nofollow noopener noreferrer">', $out);
        $this->assertStringContainsString('<a href="mailto:a@b.vn">', $out);
        $this->assertStringContainsString('<a name="z">', $out);
        $this->assertStringContainsString('<aside>x</aside> <abbr>y</abbr>', $out);
        $this->assertSame(0, preg_match('/target="_blank"[^>]*target=/', $out), 'không nhân đôi target');
        $this->assertSame($out, \App\Services\Content\ExternalLinks::normalize($out, ['https://addp.vn']), 'idempotent');
    }

    public function test_chu_thich_va_ghi_chu_co_data_tip_tabindex_aria_label_va_giu_title(): void
    {
        $out = NoteRenderer::apply('<p>A1c[^a].</p>', [['key' => 'a', 'text' => 'Hemoglobin A1c là xét nghiệm máu "đặc biệt" phản ánh đường huyết.']]);
        $this->assertMatchesRegularExpression('#<sup class="autoseo-note" data-note="a" tabindex="0" data-tip="([^"]+)" aria-label="\1" title="\1">#', $out);
        $this->assertStringContainsString('&quot;đặc biệt&quot;', $out);
        $this->assertStringContainsString('class="autoseo-notes__title"', $out);
        $this->assertSame([], $this->tagLeak($out));
        $r = $this->refs();
        $refs = ReferenceListRenderer::apply('<p>A[T1].</p>', $r, $r);
        $this->assertStringContainsString('data-tip="Nguồn: Diabetes (who.int)"', $refs);
        $this->assertStringContainsString('class="autoseo-refs__title"', $refs);
    }
}
