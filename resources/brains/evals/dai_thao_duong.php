<?php

/**
 * Bộ câu hỏi vàng cho Kho trí tuệ Đái tháo đường (bản nháp do AI soạn, CHỜ chuyên gia nội tiết gán nhãn và bổ sung).
 * Dùng để đo độ phủ truy xuất của kho (không gọi AI): với mỗi câu hỏi, các mục kho tìm được phải phủ đủ các "ý bắt buộc".
 * Mỗi ý bắt buộc là một nhóm cách nói tương đương (Việt/Anh); phủ ý khi mục tìm được chứa bất kỳ cách nói nào trong nhóm.
 * Cố ý có câu hỏi mà kho hiện chưa trả lời được (ăn uống, sổ theo dõi, gia đình, mục tiêu điều trị): kết quả "chưa phủ" là thông tin, không phải lỗi bộ đo.
 */
return [
    'domain_key' => 'dai_thao_duong',
    'status' => 'ai_draft',
    'questions' => [
        ['id' => 'q01', 'topic' => 'Hiểu về bệnh', 'q' => 'Đái tháo đường là gì?', 'must' => [['đái tháo đường', 'diabetes'], ['đường huyết', 'glucose', 'blood sugar']]],
        ['id' => 'q02', 'topic' => 'Các thể bệnh', 'q' => 'Đái tháo đường type 1 và type 2 khác nhau thế nào?', 'must' => [['type 1', 'tuýp 1'], ['type 2', 'tuýp 2'], ['insulin']]],
        ['id' => 'q03', 'topic' => 'Các thể bệnh', 'q' => 'Đái tháo đường thai kỳ là gì?', 'must' => [['thai kỳ', 'gestational'], ['mang thai', 'pregnan']]],
        ['id' => 'q04', 'topic' => 'Yếu tố nguy cơ', 'q' => 'Ai có nguy cơ mắc đái tháo đường type 2?', 'must' => [['nguy cơ', 'risk'], ['type 2', 'tuýp 2']]],
        ['id' => 'q05', 'topic' => 'Tiền đái tháo đường', 'q' => 'Tiền đái tháo đường là gì và có phòng được không?', 'must' => [['tiền đái tháo đường', 'prediabetes'], ['phòng', 'prevent', 'delay', 'trì hoãn']]],
        ['id' => 'q06', 'topic' => 'Hiểu về bệnh', 'q' => 'Trên thế giới có bao nhiêu người sống chung với đái tháo đường?', 'must' => [['triệu', 'million'], ['2022', '2019', '2021']]],
        ['id' => 'q07', 'topic' => 'Xét nghiệm định kỳ', 'q' => 'Chỉ số HbA1c bao nhiêu thì chẩn đoán đái tháo đường?', 'must' => [['hba1c', 'a1c'], ['6,5', '6.5']]],
        ['id' => 'q08', 'topic' => 'Xét nghiệm định kỳ', 'q' => 'Đường huyết lúc đói bao nhiêu là bình thường, tiền đái tháo đường, đái tháo đường?', 'must' => [['126'], ['100', '99'], ['đói', 'fasting']]],
        ['id' => 'q09', 'topic' => 'Xét nghiệm định kỳ', 'q' => 'Xét nghiệm đái tháo đường có cần nhịn ăn không?', 'must' => [['nhịn', 'fast'], ['8 giờ', '8 hours', 'fasted']]],
        ['id' => 'q10', 'topic' => 'Xét nghiệm định kỳ', 'q' => 'Bao lâu nên xét nghiệm đái tháo đường lại một lần?', 'must' => [['3 năm', '3 years', 'mỗi năm', 'every year']]],
        ['id' => 'q11', 'topic' => 'Đường huyết tại nhà', 'q' => 'Có dùng máy đo đường huyết tại nhà để chẩn đoán đái tháo đường được không?', 'must' => [['tự', 'buy', 'mua', 'tại nhà', 'over-the-counter'], ['chẩn đoán', 'diagnose']]],
        ['id' => 'q12', 'topic' => 'Sổ theo dõi', 'q' => 'Cách ghi sổ theo dõi đường huyết tại nhà như thế nào?', 'must' => [['sổ', 'log', 'diary', 'ghi chép'], ['đường huyết', 'glucose']]],
        ['id' => 'q13', 'topic' => 'Xét nghiệm định kỳ', 'q' => 'Đái tháo đường thai kỳ được chẩn đoán khi nào?', 'must' => [['thai kỳ', 'gestational'], ['glucose challenge', 'dung nạp', 'tuần', 'weeks']]],
        ['id' => 'q14', 'topic' => 'Nguyên tắc ăn uống', 'q' => 'Người đái tháo đường nên ăn uống như thế nào?', 'must' => [['ăn', 'eat', 'diet', 'meal plan'], ['rau', 'vegetable', 'carbohydrate', 'tinh bột', 'thực phẩm', 'food']]],
        ['id' => 'q15', 'topic' => 'Vận động an toàn', 'q' => 'Người đái tháo đường vận động như thế nào cho an toàn?', 'must' => [['vận động', 'physical activity', 'exercise'], ['an toàn', 'safe', 'bác sĩ', 'health care']]],
        ['id' => 'q16', 'topic' => 'Biến chứng', 'q' => 'Hạ đường huyết là gì và phòng tránh ra sao?', 'must' => [['hạ đường huyết', 'hypoglycemia', 'low blood glucose'], ['phòng', 'prevent', 'meal plan']]],
        ['id' => 'q17', 'topic' => 'Biến chứng', 'q' => 'Đái tháo đường gây những biến chứng gì?', 'must' => [['biến chứng', 'complication', 'damage'], ['mắt', 'eye', 'mù'], ['thận', 'kidney'], ['tim', 'heart']]],
        ['id' => 'q18', 'topic' => 'Chăm sóc bàn chân và mắt', 'q' => 'Chăm sóc bàn chân cho người đái tháo đường như thế nào?', 'must' => [['bàn chân', 'foot', 'feet'], ['kiểm tra', 'check', 'khám']]],
        ['id' => 'q19', 'topic' => 'Biến chứng', 'q' => 'Biến chứng thận do đái tháo đường là gì?', 'must' => [['thận', 'kidney', 'nephropathy'], ['đái tháo đường', 'diabetes']]],
        ['id' => 'q20', 'topic' => 'Chăm sóc bàn chân và mắt', 'q' => 'Đái tháo đường ảnh hưởng đến mắt như thế nào?', 'must' => [['mắt', 'eye', 'retinopathy', 'võng mạc']]],
        ['id' => 'q21', 'topic' => 'Biến chứng', 'q' => 'Đái tháo đường có làm tăng nguy cơ tim mạch không?', 'must' => [['tim', 'heart', 'cardiovascular', 'stroke', 'đột quỵ']]],
        ['id' => 'q22', 'topic' => 'Tâm lý người bệnh', 'q' => 'Trầm cảm và đái tháo đường liên quan với nhau thế nào?', 'must' => [['trầm cảm', 'depression'], ['đái tháo đường', 'diabetes']]],
        ['id' => 'q23', 'topic' => 'Hỗ trợ từ gia đình', 'q' => 'Gia đình có thể hỗ trợ người đái tháo đường như thế nào?', 'must' => [['gia đình', 'family'], ['hỗ trợ', 'support']]],
        ['id' => 'q24', 'topic' => 'Việt Nam', 'q' => 'Hướng dẫn chẩn đoán và điều trị đái tháo đường của Bộ Y tế Việt Nam là văn bản nào?', 'must' => [['bộ y tế'], ['5481', 'quyết định']]],
        ['id' => 'q25', 'topic' => 'Việt Nam', 'q' => 'Mục tiêu điều trị HbA1c theo hướng dẫn của Bộ Y tế là bao nhiêu?', 'must' => [['hba1c'], ['7%', '< 7', 'dưới 7']]],
    ],
];
