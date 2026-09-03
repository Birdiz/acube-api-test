<?php

declare(strict_types=1);

namespace App\Conversion;

use App\Conversion\Exception\UnsupportedSourceFormat;

/**
 * A file format the API accepts as input.
 *
 * The case values double as the canonical short names used in URLs, messages
 * and fixtures, so there is one spelling of "xlsx" in the project.
 */
enum SourceFormat: string
{
    case Csv = 'csv';
    case Json = 'json';
    case Xlsx = 'xlsx';
    case Ods = 'ods';

    /**
     * The MIME types that identify this format, as reported by `finfo` reading
     * the file's magic bytes — never as claimed by the client.
     *
     * Deliberately strict: `text/plain` is not accepted for CSV, even though
     * libmagic reports it for delimiter-poor files. Widening that is a decision
     * to take on purpose, with a test, rather than by accident.
     *
     * @return non-empty-list<string>
     */
    public function mimeTypes(): array
    {
        return match ($this) {
            self::Csv => ['text/csv'],
            self::Json => ['application/json'],
            self::Xlsx => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            self::Ods => ['application/vnd.oasis.opendocument.spreadsheet'],
        };
    }

    /** @throws UnsupportedSourceFormat when nothing here matches */
    public static function fromMimeType(string $mimeType): self
    {
        foreach (self::cases() as $format) {
            if (\in_array($mimeType, $format->mimeTypes(), true)) {
                return $format;
            }
        }

        throw UnsupportedSourceFormat::forMimeType($mimeType);
    }
}
