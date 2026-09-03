<?php

declare(strict_types=1);

namespace App\File\Exception;

use App\Conversion\Exception\ConversionProblem;

final class UnknownFile extends ConversionProblem
{
    public static function withId(string $fileId): self
    {
        return new self(\sprintf('No uploaded file has the id "%s".', $fileId));
    }

    public function status(): int
    {
        return 404;
    }

    public function type(): string
    {
        return '/errors/unknown-file';
    }

    public function title(): string
    {
        return 'Unknown file';
    }
}
