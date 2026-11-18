<?php

namespace App\Services\Planning\Import;

use InvalidArgumentException;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\ODS\Reader as OdsReader;
use OpenSpout\Reader\ReaderInterface;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Đọc file kế hoạch người dùng tải lên (xlsx, csv, ods) thành bảng chữ thuần. Không chạy công thức/macro: chỉ lấy giá trị ô.
 * Có trần dung lượng, số sheet, số dòng, số cột để một file lạ không làm nghẽn máy chủ. Số dòng trả về GIỮ NGUYÊN số dòng của sheet
 * (chỉ số mảng + 1), kể cả dòng trống, để "dòng tiêu đề" AI báo khớp đúng file gốc.
 */
final class SheetReader
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    public const MAX_SHEETS = 8;

    public const MAX_ROWS = 2000;

    public const MAX_COLS = 40;

    public const MAX_CELL = 500;

    public const EXTENSIONS = ['xlsx', 'csv', 'ods'];

    /**
     * @return list<array{name:string, rows:list<list<string>>, total:int, truncated:bool}> chỉ các sheet có dữ liệu
     *
     * @throws InvalidArgumentException file không đọc được hoặc không đúng định dạng
     */
    public function read(string $path, string $extension, ?int $size = null): array
    {
        $ext = strtolower(trim($extension));
        if (! in_array($ext, self::EXTENSIONS, true)) {
            throw new InvalidArgumentException('Chỉ nhận file .xlsx, .csv hoặc .ods. File .xls cũ: mở bằng Excel rồi lưu lại thành .xlsx.');
        }
        if (! is_file($path) || ($size ?? filesize($path)) > self::MAX_BYTES) {
            throw new InvalidArgumentException('File quá lớn (tối đa '.(self::MAX_BYTES / 1048576).' MB) hoặc không đọc được.');
        }

        $reader = $this->open($path, $ext);
        $sheets = [];
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                if (count($sheets) >= self::MAX_SHEETS) {
                    break;
                }
                $rows = [];
                $total = 0;
                $truncated = false;
                foreach ($sheet->getRowIterator() as $row) {
                    if (++$total > self::MAX_ROWS) {
                        $truncated = true;
                        $total = self::MAX_ROWS;
                        break;
                    }
                    $cells = array_slice(array_map([$this, 'cell'], $row->toArray()), 0, self::MAX_COLS);
                    while ($cells !== [] && end($cells) === '') {
                        array_pop($cells);
                    }
                    $rows[] = $cells;
                }
                while ($rows !== [] && end($rows) === []) {
                    array_pop($rows);
                }
                if ($rows !== []) {
                    $sheets[] = ['name' => mb_substr(trim((string) $sheet->getName()) ?: 'Sheet '.(count($sheets) + 1), 0, 80), 'rows' => $rows, 'total' => count($rows), 'truncated' => $truncated];
                }
            }
        } catch (\Throwable $e) {
            throw new InvalidArgumentException('Không đọc được file: có thể file hỏng, có mật khẩu hoặc sai định dạng.', 0, $e);
        } finally {
            $reader->close();
        }
        if ($sheets === []) {
            throw new InvalidArgumentException('File không có dữ liệu để đọc.');
        }

        return $sheets;
    }

    private function open(string $path, string $ext): ReaderInterface
    {
        try {
            $reader = match ($ext) {
                'xlsx' => new XlsxReader,
                'ods' => new OdsReader,
                default => new CsvReader($this->csvOptions($path)),
            };
            $reader->open($path);

            return $reader;
        } catch (\Throwable $e) {
            throw new InvalidArgumentException('Không mở được file: có thể file hỏng, có mật khẩu hoặc sai định dạng.', 0, $e);
        }
    }

    /** Excel tiếng Việt thường lưu CSV phân tách bằng dấu chấm phẩy: đoán dấu phân tách theo dòng đầu. */
    private function csvOptions(string $path): CsvOptions
    {
        $o = new CsvOptions;
        $head = (string) file_get_contents($path, false, null, 0, 4096);
        $first = strtok(ltrim($head, "\xEF\xBB\xBF"), "\n") ?: '';
        $counts = [',' => substr_count($first, ','), ';' => substr_count($first, ';'), "\t" => substr_count($first, "\t")];
        arsort($counts);
        $o->FIELD_DELIMITER = (string) array_key_first($counts);
        if ($counts[$o->FIELD_DELIMITER] === 0) {
            $o->FIELD_DELIMITER = ',';
        }

        return $o;
    }

    private function cell(mixed $v): string
    {
        if ($v instanceof \DateTimeInterface) {
            return $v->format($v->format('H:i:s') === '00:00:00' ? 'Y-m-d' : 'Y-m-d H:i');
        }
        if ($v instanceof \DateInterval) {
            return '';
        }
        if (is_bool($v)) {
            return $v ? 'TRUE' : 'FALSE';
        }
        if (is_float($v) && floor($v) === $v && abs($v) < 1e15) {
            $v = (int) $v;
        }
        $t = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', is_scalar($v) ? (string) $v : '') ?? '';

        return mb_substr(trim($t), 0, self::MAX_CELL);
    }
}
