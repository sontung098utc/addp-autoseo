<?php

namespace App\Services\Keywords;

use App\Contracts\StructuredAiInterface;
use App\Services\Agent\Ai\AiModelRouter;
use App\Services\Agent\Settings\AgentAi;
use App\Services\Ai\DemoScriptedAiProvider;

/**
 * G3: chọn AI cho các việc từ khóa (keyword_suggest, keyword_cluster), cùng cách KnowledgeAiIngest::resolve làm.
 * Thứ tự: route của loại việc, rồi route 'summary' (cùng bậc rẻ), rồi AI đã kết nối; riêng môi trường local dùng bản giả lập CÓ NHÃN.
 * Không bao giờ dùng bản giả lập im lặng ở môi trường khác: thiếu AI thật thì ném KeywordAiUnavailable.
 */
final class KeywordAiResolver
{
    /** @return array{ai: StructuredAiInterface, label: string, cap: ?float, demo: bool} */
    public static function resolve(string $task): array
    {
        if (AiModelRouter::isConfigured()) {
            $router = new AiModelRouter;
            foreach ([$task, 'summary'] as $t) {
                $target = $router->target($t);
                if ($target && $target->ai instanceof StructuredAiInterface) {
                    return ['ai' => $target->ai, 'label' => $target->label(), 'cap' => $target->costCapVnd, 'demo' => false];
                }
            }
            throw new KeywordAiUnavailable('Chưa kết nối AI. '.($router->why($task) ?? 'Chưa có route phù hợp.').' Cấu hình ở Cài đặt → Tích hợp AI.');
        }
        $real = AgentAi::real();
        if ($real instanceof StructuredAiInterface) {
            return ['ai' => $real, 'label' => 'AI đã kết nối', 'cap' => null, 'demo' => false];
        }
        if (app()->environment('local')) {
            return ['ai' => new DemoScriptedAiProvider, 'label' => 'AI giả lập (môi trường local)', 'cap' => null, 'demo' => true];
        }
        throw new KeywordAiUnavailable('Chưa kết nối AI. Cấu hình ở Cài đặt → Tích hợp AI.');
    }

    /** @return array{ok: bool, reason: ?string, label: ?string, demo: bool} cho giao diện */
    public static function availability(string $task): array
    {
        try {
            $r = self::resolve($task);

            return ['ok' => true, 'reason' => null, 'label' => $r['label'], 'demo' => $r['demo']];
        } catch (KeywordAiUnavailable $e) {
            return ['ok' => false, 'reason' => $e->getMessage(), 'label' => null, 'demo' => false];
        } catch (\Throwable) {
            return ['ok' => false, 'reason' => 'Chưa kết nối AI.', 'label' => null, 'demo' => false];
        }
    }
}
