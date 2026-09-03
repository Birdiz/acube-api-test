<?php

declare(strict_types=1);

namespace App\File\Exception;

use App\Conversion\Exception\ConversionProblem;

/**
 * The three ways a file can be too big: our own limit, the same limit reached
 * one layer down in PHP, and a body PHP threw away before we saw it. All three
 * are the same answer to the caller, so all three name the same number.
 */
final class UploadTooLarge extends ConversionProblem
{
    public static function forSize(int $sizeBytes, int $maxBytes): self
    {
        return new self(\sprintf(
            'The file is %d bytes; the maximum accepted size is %d bytes.',
            $sizeBytes,
            $maxBytes,
        ));
    }

    public static function refusedByPhp(int $maxBytes): self
    {
        return new self(\sprintf(
            'The upload exceeded the server limit and was refused before it completed. '
            .'The maximum accepted size is %d bytes.',
            $maxBytes,
        ));
    }

    public static function discardedByPhp(int $announcedBytes, int $maxBytes): self
    {
        return new self(\sprintf(
            'The request announced %d bytes and was discarded before it could be read. '
            .'The maximum accepted size is %d bytes.',
            $announcedBytes,
            $maxBytes,
        ));
    }

    public function status(): int
    {
        return 413;
    }

    public function type(): string
    {
        return '/errors/file-too-large';
    }

    public function title(): string
    {
        return 'File too large';
    }
}
