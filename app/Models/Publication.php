<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Publication extends Model
{
    use HasFactory;

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_PUBLISHING = 'publishing';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_FAILED = 'failed';

    public const STATUS_NEEDS_RECONCILE = 'needs_reconcile';

    public const STATUS_HELD = 'held';

    /** Người dừng riêng một bài (nút "Tạm dừng bài này"). Không bao giờ được gửi lên CMS. */
    public const STATUS_PAUSED = 'paused';

    /** Chiến dịch đặt điểm dừng "Trước khi đăng": chờ người xác nhận. Không bao giờ được gửi lên CMS. */
    public const STATUS_AWAITING_CONFIRM = 'awaiting_confirm';

    public const ALL_STATUSES = [
        self::STATUS_SCHEDULED,
        self::STATUS_PUBLISHING,
        self::STATUS_PUBLISHED,
        self::STATUS_FAILED,
        self::STATUS_NEEDS_RECONCILE,
        self::STATUS_HELD,
        self::STATUS_PAUSED,
        self::STATUS_AWAITING_CONFIRM,
    ];

    protected $fillable = [
        'website_id',
        'article_version_id',
        'publication_key',
        'scheduled_at_utc',
        'timezone',
        'status',
        'cms_post_id',
        'published_url',
        'attempt_count',
        'last_error',
        'published_at_utc',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at_utc' => 'datetime',
            'published_at_utc' => 'datetime',
            'attempt_count' => 'integer',
        ];
    }

    /**
     * Điểm dừng 'publish' của chiến dịch: bài thuộc chiến dịch có điểm dừng này thì lượt đăng mới tạo vào "Chờ xác nhận"
     * (không bao giờ được gửi cho tới khi người xác nhận). Đặt ở đây để mọi nơi xếp lịch (duyệt tay, Pilot) đều được áp dụng.
     */
    protected static function booted(): void
    {
        static::created(function (Publication $p) {
            if ($p->status !== 'scheduled') {
                return;
            }
            $campaignId = $p->articleVersion?->article?->campaign_id;
            $c = $campaignId ? Campaign::query()->find($campaignId) : null;
            if ($c && in_array('publish', (array) $c->checkpoints, true)) {
                $p->forceFill(['status' => 'awaiting_confirm', 'last_error' => 'Chờ xác nhận: chiến dịch đặt điểm dừng "Trước khi đăng".'])->saveQuietly();
            }
        });
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function articleVersion(): BelongsTo
    {
        return $this->belongsTo(ArticleVersion::class);
    }

    public function isHeld(): bool
    {
        return $this->status === self::STATUS_HELD;
    }

    public function needsReconcile(): bool
    {
        return $this->status === self::STATUS_NEEDS_RECONCILE;
    }
}
