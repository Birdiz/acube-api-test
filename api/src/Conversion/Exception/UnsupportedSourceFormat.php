<?php

declare(strict_types=1);

namespace App\Conversion\Exception;

use App\Conversion\SourceFormat;

/** The uploaded file is not a type we can convert from. */
final class UnsupportedSourceFormat extends ConversionProblem
{
    public static function forMimeType(string $mimeType): self
    {
        return new self(\sprintf(
            'Files of type "%s" cannot be converted. Supported types are: %s.',
            $mimeType,
            implode(', ', array_column(SourceFormat::cases(), 'value')),
        ));
    }

    public function status(): int
    {
        return 415;
    }

    public function type(): string
    {
        return '/errors/unsupported-file-type';
    }

    public function title(): string
    {
        return 'Unsupported file type';
    }
}
