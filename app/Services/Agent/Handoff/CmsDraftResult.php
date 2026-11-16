<?php

namespace App\Services\Agent\Handoff;

/** Kết quả một lần thử đẩy nháp. status: pushed | failed | skipped. note chỉ có khi cần nói cho người dùng biết. */
final class CmsDraftResult
{
    public function __construct(
        public readonly string $status,
        public readonly ?string $note = null,
        public readonly ?string $cms = null,
        public readonly ?string $url = null,
        public readonly ?string $remoteId = null,
    ) {
    }

    public static function skipped(?string $note = null): self
    {
        return new self('skipped', $note);
    }
}
