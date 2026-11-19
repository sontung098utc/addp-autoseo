<?php

namespace App\Support;

use App\Filament\Support\Schedule\ErrorMasker;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Biến một ngoại lệ thành câu tiếng Việt AN TOÀN để hiện cho người dùng (AGENTS.md §1.1: không lộ đường dẫn,
 * câu SQL, tên lớp, URL có khóa). Lỗi nghiệp vụ do chính dự án ném giữ nguyên thông điệp (sau khi lọc);
 * lỗi hệ thống/không rõ nguồn gốc thành câu chung + mã tham chiếu, chi tiết đầy đủ chỉ ghi vào log.
 */
final class UserFacingError
{
    public const GENERIC = 'Có lỗi hệ thống, đã ghi lại để quản trị kiểm tra. Hãy thử lại sau ít phút.';

    /** Loại ngoại lệ KHÔNG BAO GIỜ coi là an toàn dù ném từ mã của dự án (thông điệp do PHP/thư viện sinh). */
    private const NEVER_SAFE = [
        \Error::class,
        \ErrorException::class,
        \PDOException::class,
        QueryException::class,
        \JsonException::class,
        \Illuminate\Http\Client\ConnectionException::class,
        \Illuminate\Http\Client\RequestException::class,
    ];

    public static function from(Throwable $e, ?string $context = null): string
    {
        if (self::isSafeType($e)) {
            $msg = trim($e instanceof ValidationException ? implode(' ', array_merge(...array_values($e->errors()))) : $e->getMessage());
            if ($msg !== '' && ! self::looksSensitive($msg)) {
                return $msg;
            }
        }

        $ref = self::reference();
        try {
            Log::error('Lỗi hiển thị cho người dùng ['.$ref.']', [
                'ref' => $ref,
                'context' => $context,
                'page' => self::page(),
                'user_id' => self::userId(),
                'exception' => $e::class,
                // Chi tiết đầy đủ chỉ nằm trong log máy chủ, đã che khóa/token.
                'message' => ErrorMasker::mask($e->getMessage()),
                'where' => $e->getFile().':'.$e->getLine(),
            ]);
        } catch (Throwable) {
            // log hỏng cũng không được làm lỗi thêm ra giao diện
        }

        return self::GENERIC.' (Mã tham chiếu: '.$ref.')';
    }

    /** Lỗi do mã dự án ném có chủ đích: ngoại lệ miền, hoặc ngoại lệ chuẩn PHP ném từ app/ (không phải lỗi PHP/thư viện). */
    private static function isSafeType(Throwable $e): bool
    {
        foreach (self::NEVER_SAFE as $bad) {
            if ($e instanceof $bad) {
                return false;
            }
        }
        if ($e instanceof ValidationException || $e instanceof AuthorizationException || $e instanceof \InvalidArgumentException || $e instanceof \DomainException) {
            return true;
        }
        if (str_starts_with($e::class, 'App\\')) {
            return true;
        }

        $file = str_replace('\\', '/', $e->getFile());
        $app = str_replace('\\', '/', app_path()).'/';

        return str_starts_with($file, $app) && ($e instanceof \RuntimeException || $e instanceof \LogicException);
    }

    /** Thông điệp an toàn về loại nhưng chứa đường dẫn, câu SQL, tên lớp, URL có query hay chuỗi giống khóa. */
    private static function looksSensitive(string $msg): bool
    {
        if (preg_match('~(?<![\w.])(/(?:home|var|usr|etc|tmp|opt|srv|root|app|vendor|storage|www)\b[\w./\-]*|[A-Za-z]:\\\\[\w.\\\\\-]+)~', $msg)) {
            return true;
        }
        if (preg_match('~\b(?:storage/framework|vendor/|file_put_contents|file_get_contents|fopen|stream_|SQLSTATE|Stack trace)\b~i', $msg)) {
            return true;
        }
        if (preg_match('~\b(?:select|insert into|update|delete from)\b.+\b(?:from|values|set|where)\b~i', $msg)) {
            return true;
        }
        if (preg_match('~\b[A-Z][A-Za-z0-9_]*(?:\\\\[A-Za-z0-9_]+){1,}~', $msg)) { // Foo\Bar\Baz
            return true;
        }
        if (preg_match('~https?://[^\s]*\?[^\s]+~i', $msg)) {
            return true;
        }

        return ErrorMasker::mask($msg) !== $msg;
    }

    private static function reference(): string
    {
        return strtoupper(Str::random(6));
    }

    private static function page(): ?string
    {
        try {
            return app()->bound('request') ? request()->path() : null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function userId(): int|string|null
    {
        try {
            return \Illuminate\Support\Facades\Auth::id();
        } catch (Throwable) {
            return null;
        }
    }
}
