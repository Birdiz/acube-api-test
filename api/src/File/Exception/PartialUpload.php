<?php

declare(strict_types=1);

namespace App\File\Exception;

use App\Conversion\Exception\ConversionProblem;

/** The connection dropped mid-upload: the caller's to retry, not ours to fix. */
final class PartialUpload extends ConversionProblem
{
    public static function wasReceived(): self
    {
        return new self('The upload ended before the whole file arrived. Send it again.');
    }

    public function status(): int
    {
        return 422;
    }

    public function type(): string
    {
        return '/errors/partial-upload';
    }

    public function title(): string
    {
        return 'Incomplete upload';
    }
}
