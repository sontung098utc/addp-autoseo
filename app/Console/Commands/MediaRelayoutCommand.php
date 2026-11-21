<?php

namespace App\Console\Commands;

use App\Models\MediaAsset;
use App\Services\Media\MediaPathBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Chuẩn bị chuyển ảnh cũ sang lưu trữ theo địa chỉ nội dung. Mặc định chỉ BÁO CÁO (dry-run).
 * Với --apply: chỉ SAO CHÉP file sang đường dẫn chuẩn và ghi metadata.cas_path/cas_thumb_path.
 * Không đổi file_path/thumbnail_path, không xóa/di chuyển file nào: URL đã đăng lên CMS (đặc biệt ảnh trong thân bài Magento) giữ nguyên.
 */
class MediaRelayoutCommand extends Command
{
    protected $signature = 'media:relayout {--apply : Sao chép file sang đường dẫn chuẩn (mặc định chỉ báo cáo)}';

    protected $description = 'Báo cáo/chuẩn bị chuyển thư viện ảnh sang lưu trữ theo địa chỉ nội dung (không đổi file_path, không xóa file)';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $stats = ['assets' => 0, 'missing' => 0, 'canonical' => 0, 'legacy' => 0, 'copied' => 0, 'already' => 0, 'failed' => 0, 'in_articles' => 0];
        $bySha = [];

        MediaAsset::query()->orderBy('id')->chunkById(200, function ($assets) use (&$stats, &$bySha, $apply): void {
            foreach ($assets as $a) {
                $stats['assets']++;
                $disk = Storage::disk($a->disk ?: 'public');
                $path = (string) $a->file_path;
                if (! $disk->exists($path)) {
                    $stats['missing']++;
                    $this->warn("#{$a->id}: file không còn trên ổ lưu trữ ({$path})");

                    continue;
                }
                if ($a->usage_count > 0 || $a->articleVersions()->exists()) {
                    $stats['in_articles']++;
                }
                $bySha[$a->sha256][] = ['id' => $a->id, 'path' => $path, 'size' => (int) $disk->size($path)];
                if (MediaPathBuilder::isCanonical($path)) {
                    $stats['canonical']++;

                    continue;
                }
                $stats['legacy']++;
                if (! $apply) {
                    continue;
                }
                try {
                    $this->copyToCanonical($a, $disk);
                    isset($a->metadata['cas_path']) ? $stats['already']++ : $stats['copied']++;
                } catch (\Throwable $e) {
                    $stats['failed']++;
                    $this->error("#{$a->id}: không sao chép được ({$e->getMessage()})");
                }
            }
        });

        $dupGroups = 0;
        $dupBytes = 0;
        foreach ($bySha as $files) {
            $paths = array_unique(array_column($files, 'path'));
            if (count($paths) > 1) {
                $dupGroups++;
                $sizes = [];
                foreach ($files as $f) {
                    $sizes[$f['path']] = $f['size'];
                }
                $dupBytes += array_sum($sizes) - max($sizes);
            }
        }

        $this->info(($apply ? 'ÁP DỤNG' : 'DRY-RUN')." — tổng {$stats['assets']} ảnh; thiếu file {$stats['missing']}; đã ở đường dẫn chuẩn {$stats['canonical']}; còn đường dẫn cũ {$stats['legacy']}.");
        $this->info("Đang gắn vào bài (không được đổi file_path): {$stats['in_articles']}. Nhóm file trùng nội dung: {$dupGroups}, có thể giải phóng khoảng ".round($dupBytes / 1048576, 2).' MB (chưa xóa gì).');
        if ($apply) {
            $this->info("Sao chép mới {$stats['copied']}; đã có sẵn {$stats['already']}; lỗi {$stats['failed']}. file_path không đổi.");
        } else {
            $this->line('Chạy lại với --apply để sao chép (không xóa, không đổi file_path).');
        }

        return $stats['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function copyToCanonical(MediaAsset $a, $disk): void
    {
        $target = MediaPathBuilder::original($a->sha256);
        $thumbTarget = MediaPathBuilder::thumb($a->sha256);
        if (! $disk->exists($target)) {
            if (! $disk->copy((string) $a->file_path, $target) || $disk->size($target) !== $disk->size((string) $a->file_path)) {
                $disk->delete($target); // chỉ xóa bản sao dở do chính lệnh này vừa tạo
                throw new \RuntimeException('bản sao không khớp kích thước');
            }
        }
        $hasThumb = $a->thumbnail_path && $disk->exists((string) $a->thumbnail_path);
        if ($hasThumb && ! $disk->exists($thumbTarget)) {
            $disk->copy((string) $a->thumbnail_path, $thumbTarget);
        }
        $a->update(['metadata' => array_merge((array) $a->metadata, [
            'cas_path' => $target,
            'cas_thumb_path' => $hasThumb ? $thumbTarget : null,
        ])]);
    }
}
