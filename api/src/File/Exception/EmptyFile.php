<?php

declare(strict_types=1);

namespace App\File\Exception;

use App\Conversion\Exception\ConversionProblem;

final class EmptyFile extends ConversionProblem
{
    public static function wasUploaded(): self
    {
        return new self('The uploaded file is empty, so there is nothing to convert.');
    }

    public function status(): int
    {
        return 422;
    }

    public function type(): string
    {
        return '/errors/empty-file';
    }

    public function title(): string
    {
        return 'Empty file';
    }
}
