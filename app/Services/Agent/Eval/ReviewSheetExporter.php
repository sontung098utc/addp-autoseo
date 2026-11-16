<?php

namespace App\Services\Agent\Eval;

use App\Models\ArticleVersion;
use App\Models\KnowledgeItem;
use App\Services\Content\CitationSupportJudge;
use App\Services\Content\Quality\QualityContext;

/**
 * Q0.4: xuất PHIẾU CHẤM cho người (dược sĩ) thành MỘT tệp HTML tự chứa, mở bằng trình duyệt, không cần máy chủ và không gọi ra ngoài.
 * Người chấm không cần biết y khoa cho phần đối chiếu: chỉ trả lời "nguồn có thật sự nói đúng như câu trong bài không?".
 * Gồm, cho mỗi bài: (A) câu hỏi bắt buộc có được trả lời không; (B) câu trong bài ↔ đoạn nguồn; (C) câu có số liệu nhưng chưa dẫn nguồn;
 * (D) phán quyết toàn bài. Kết quả tải xuống thành tệp JSON `autoseo-eval-labels/1` (kèm nhãn AI để đo giám khảo, xem JudgeCalibration).
 * Mọi nội dung bài đi vào trang bằng textContent (không innerHTML) nên bài do AI viết không thể chèn mã vào phiếu.
 */
final class ReviewSheetExporter
{
    public const SCHEMA = 'autoseo-eval-labels/1';

    public const MAX_PAIRS = 24;

    public const MAX_UNCITED = 12;

    /**
     * @param  list<array{version: ArticleVersion, metrics: array<string,mixed>, query: ?GoldenQuery}>  $entries
     */
    public function html(array $entries, string $title = 'Phiếu chấm chất lượng bài'): string
    {
        $data = ['schema' => self::SCHEMA, 'title' => $title, 'sheet_id' => substr(sha1(json_encode(array_map(fn ($e) => $e['version']->id, $entries)).$title), 0, 12), 'entries' => array_map(fn ($e) => $this->entry($e), $entries)];
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);

        return str_replace(['__TITLE__', '__DATA__'], [htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $json], self::TEMPLATE);
    }

    /**
     * @param  array{version: ArticleVersion, metrics: array<string,mixed>, query: ?GoldenQuery}  $e
     * @return array<string,mixed>
     */
    public function entry(array $e): array
    {
        /** @var ArticleVersion $v */
        $v = $e['version'];
        $m = $e['metrics'];
        $html = (string) $v->html_content;

        $verdicts = [];
        foreach ((array) ($m['claims']['judge']['verdicts'] ?? []) as $d) {
            $verdicts[(string) ($d['excerpt'] ?? '')] = (string) ($d['verdict'] ?? '');
        }
        $pairs = array_slice(CitationSupportJudge::pairs($html), 0, self::MAX_PAIRS);
        $items = KnowledgeItem::query()->with('source')->whereIn('id', $pairs === [] ? [0] : array_unique(array_merge(...array_column($pairs, 'ids'))))->get()->keyBy('id');
        $outPairs = [];
        foreach ($pairs as $p) {
            $sources = [];
            foreach ($p['ids'] as $id) {
                if ($it = $items->get($id)) {
                    $sources[] = ['id' => $id, 'title' => mb_substr((string) $it->title, 0, 160), 'body' => mb_substr((string) $it->body, 0, 700), 'origin' => (string) ($it->source?->title ?? '')];
                }
            }
            $outPairs[] = [
                'key' => substr(sha1($v->id.'|'.$p['text']), 0, 10),
                'text' => mb_substr($p['text'], 0, 600),
                'sources' => $sources,
                'ai' => $verdicts[QualityContext::excerpt($p['text'], 90)] ?? '',
            ];
        }
        $uncited = [];
        foreach (array_slice((new FactSentenceDetector)->analyze($html)['uncited'], 0, self::MAX_UNCITED) as $s) {
            $uncited[] = ['key' => substr(sha1($v->id.'|u|'.$s), 0, 10), 'text' => mb_substr($s, 0, 400)];
        }

        return [
            'version_id' => $v->id,
            'article_id' => $v->article_id,
            'keyword' => (string) ($m['keyword'] ?? ''),
            'words' => (int) ($m['words'] ?? 0),
            'model' => (string) ($m['model'] ?? ''),
            'hygiene' => $m['hygiene']['score'] ?? null,
            'must_answer' => array_map(fn ($i) => ['id' => $i['id'], 'q' => $i['q'], 'ai' => (string) ($i['status'] ?? ''), 'quote' => (string) ($i['quote'] ?? '')], $this->mustAnswer($m, $e['query'])),
            'pairs' => $outPairs,
            'uncited' => $uncited,
        ];
    }

    /**
     * Câu hỏi bắt buộc của bài. Chưa chạy giám khảo AI thì vẫn liệt kê câu hỏi của từ khóa chuẩn (nhãn AI để trống) để người chấm trả lời.
     *
     * @param  array<string,mixed>  $m
     * @return list<array<string,mixed>>
     */
    private function mustAnswer(array $m, ?GoldenQuery $q): array
    {
        $items = (array) ($m['intent']['items'] ?? []);
        if ($items === [] && $q !== null) {
            $items = array_map(fn ($x) => ['id' => $x['id'], 'q' => $x['q'], 'status' => '', 'quote' => ''], $q->mustAnswer);
        }

        return $items;
    }

    private const TEMPLATE = <<<'HTML'
<!doctype html>
<html lang="vi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>__TITLE__</title>
<style>
:root{--bg:#fafaf7;--fg:#1c1b19;--mut:#6a665e;--card:#fff;--line:#e2dfd6;--acc:#1f5f5b;--ok:#1b7a3d;--bad:#a33;--warn:#8a6100}
@media (prefers-color-scheme:dark){:root{--bg:#161614;--fg:#ecebe6;--mut:#a09d94;--card:#1f1f1c;--line:#34332e;--acc:#6bc4bd;--ok:#6fd08c;--bad:#ef8a8a;--warn:#e6b84d}}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--fg);font:16px/1.55 system-ui,sans-serif}
main{max-width:860px;margin:0 auto;padding:16px}
h1{font-size:1.3rem;margin:.2em 0}h2{font-size:1.1rem;margin:1.2em 0 .4em}h3{font-size:1rem;margin:1em 0 .3em;color:var(--acc)}
.card{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:14px;margin:12px 0}
.mut{color:var(--mut);font-size:.9rem}.q{font-weight:600}
.src{border-left:3px solid var(--line);padding:4px 10px;margin:6px 0;font-size:.92rem;color:var(--mut)}
.row{display:flex;flex-wrap:wrap;gap:6px 16px;margin:6px 0}label{cursor:pointer;display:inline-flex;gap:6px;align-items:center;min-height:32px}
.tag{display:inline-block;border:1px solid var(--line);border-radius:6px;padding:0 6px;font-size:.8rem;color:var(--mut)}
textarea,input[type=text]{width:100%;font:inherit;padding:8px;border:1px solid var(--line);border-radius:8px;background:var(--card);color:var(--fg)}
button{font:inherit;padding:10px 16px;border-radius:8px;border:1px solid var(--acc);background:var(--acc);color:#fff;cursor:pointer}
#bar{position:sticky;top:0;background:var(--bg);padding:8px 0;border-bottom:1px solid var(--line);z-index:2}
</style>
</head>
<body>
<main>
<div id="bar"><h1 id="t"></h1><div class="mut" id="prog"></div></div>
<p class="mut">Bạn không cần biết y khoa cho phần đối chiếu: chỉ cần đọc <b>câu trong bài</b> và <b>đoạn nguồn</b>, rồi chọn nguồn có nói đúng như câu đó không. Gặp chỗ không chắc, chọn "Không rõ". Phần cuối mỗi bài hỏi nhận định chung của bạn (dùng được ngay, sửa nhẹ, sửa nặng hay loại).</p>
<div id="root"></div>
<div class="card"><label class="q" for="rev">Tên người chấm</label><input type="text" id="rev" placeholder="Họ tên">
<p><button id="dl">Tải kết quả (JSON)</button> <span class="mut" id="msg"></span></p>
<p class="mut">Phiếu tự lưu tạm trong trình duyệt này. Gửi tệp JSON tải về cho người phụ trách để nhập vào hệ thống.</p></div>
</main>
<script id="data" type="application/json">__DATA__</script>
<script>
(function(){
var D=JSON.parse(document.getElementById('data').textContent);
var KEY='autoseo-sheet-'+D.sheet_id, S={};
try{S=JSON.parse(localStorage.getItem(KEY)||'{}')}catch(e){S={}}
function save(){try{localStorage.setItem(KEY,JSON.stringify(S))}catch(e){}prog()}
function el(t,c,x){var e=document.createElement(t);if(c)e.className=c;if(x!==undefined)e.textContent=x;return e}
function radios(name,opts,cur,on){var r=el('div','row');opts.forEach(function(o){var l=el('label');var i=document.createElement('input');i.type='radio';i.name=name;i.value=o[0];i.checked=cur===o[0];i.onchange=function(){on(o[0])};l.appendChild(i);l.appendChild(document.createTextNode(o[1]));r.appendChild(l)});return r}
var AIM={answered:'AI: đã trả lời',partial:'AI: một phần',evasive:'AI: nói vòng',missing:'AI: không có',supported:'AI: hỗ trợ',unsupported:'AI: không hỗ trợ'};
var total=0;
function mk(k){return 'k'+k}
document.getElementById('t').textContent=D.title;
var root=document.getElementById('root');
D.entries.forEach(function(E){
 var c=el('section','card');
 c.appendChild(el('h2','', E.keyword+'  (bản '+E.version_id+')'));
 c.appendChild(el('div','mut', E.words+' từ'+(E.model?' · '+E.model:'')+(E.hygiene!==null?' · vệ sinh SEO '+E.hygiene:'')));
 var st=S[E.version_id]=S[E.version_id]||{ma:{},pr:{},un:{},v:'',n:''};
 if(E.must_answer.length){c.appendChild(el('h3','','A. Bài có trả lời các câu hỏi bắt buộc không?'));
  E.must_answer.forEach(function(m){total++;var b=el('div');b.appendChild(el('div','q',m.q));
   if(m.ai||m.quote)b.appendChild(el('div','mut',(AIM[m.ai]||m.ai||'')+(m.quote?' · "'+m.quote+'"':'')));
   b.appendChild(radios('ma'+E.version_id+m.id,[['co','Có, cụ thể'],['mot_phan','Một phần'],['noi_vong','Nói vòng'],['khong','Không']],st.ma[m.id],function(v){st.ma[m.id]=v;save()}));c.appendChild(b)})}
 if(E.pairs.length){c.appendChild(el('h3','','B. Câu trong bài có đúng với đoạn nguồn không?'));
  E.pairs.forEach(function(p){total++;var b=el('div','card');b.appendChild(el('div','q',p.text));
   p.sources.forEach(function(s){var d=el('div','src');d.appendChild(el('b','',s.title+': '));d.appendChild(document.createTextNode(s.body));b.appendChild(d)});
   if(!p.sources.length)b.appendChild(el('div','mut','(không tìm thấy mục nguồn)'));
   if(p.ai)b.appendChild(el('div','mut',AIM[p.ai]||p.ai));
   b.appendChild(radios('pr'+E.version_id+p.key,[['dung','Đúng'],['khong_ro','Không rõ'],['sai','Sai']],st.pr[p.key],function(v){st.pr[p.key]=v;save()}));c.appendChild(b)})}
 if(E.uncited.length){c.appendChild(el('h3','','C. Câu có số liệu/khẳng định nhưng chưa dẫn nguồn'));
  E.uncited.forEach(function(u){total++;var b=el('div');b.appendChild(el('div','q',u.text));
   b.appendChild(radios('un'+E.version_id+u.key,[['can_nguon','Cần nguồn'],['khong_can','Không cần nguồn']],st.un[u.key],function(v){st.un[u.key]=v;save()}));c.appendChild(b)})}
 c.appendChild(el('h3','','D. Nhận định chung về bài'));total++;
 c.appendChild(radios('v'+E.version_id,[['dung_duoc','Dùng được ngay'],['sua_nhe','Sửa nhẹ'],['sua_nang','Sửa nặng'],['loai','Loại']],st.v,function(v){st.v=v;save()}));
 var ta=el('textarea');ta.rows=2;ta.placeholder='Ghi chú (tuỳ chọn)';ta.value=st.n||'';ta.oninput=function(){st.n=ta.value;save()};c.appendChild(ta);
 root.appendChild(c)});
function done(){var n=0;D.entries.forEach(function(E){var s=S[E.version_id]||{};E.must_answer.forEach(function(m){if((s.ma||{})[m.id])n++});E.pairs.forEach(function(p){if((s.pr||{})[p.key])n++});E.uncited.forEach(function(u){if((s.un||{})[u.key])n++});if(s.v)n++});return n}
function prog(){document.getElementById('prog').textContent='Đã chọn '+done()+' / '+total+' mục'}
prog();
var rev=document.getElementById('rev');rev.value=S.__rev||'';rev.oninput=function(){S.__rev=rev.value;save()};
document.getElementById('dl').onclick=function(){
 var out={schema:D.schema,sheet_id:D.sheet_id,reviewer:rev.value,created_at:new Date().toISOString(),labels:D.entries.map(function(E){var s=S[E.version_id]||{};
  return{version_id:E.version_id,article_id:E.article_id,keyword:E.keyword,
   must_answer:E.must_answer.map(function(m){return{id:m.id,ai:m.ai,human:(s.ma||{})[m.id]||''}}),
   pairs:E.pairs.map(function(p){return{key:p.key,ai:p.ai,human:(s.pr||{})[p.key]||''}}),
   uncited:E.uncited.map(function(u){return{key:u.key,human:(s.un||{})[u.key]||''}}),
   verdict:s.v||'',note:s.n||''}})};
 var b=new Blob([JSON.stringify(out,null,1)],{type:'application/json'});var a=document.createElement('a');a.href=URL.createObjectURL(b);a.download='nhan-cham-'+D.sheet_id+'.json';document.body.appendChild(a);a.click();a.remove();
 document.getElementById('msg').textContent='Đã tải tệp. Còn '+(total-done())+' mục chưa chọn.'};
})();
</script>
</body>
</html>
HTML;
}
