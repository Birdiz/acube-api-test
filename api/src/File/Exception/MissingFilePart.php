<?php

declare(strict_types=1);

namespace App\File\Exception;

use App\Conversion\Exception\ConversionProblem;

final class MissingFilePart extends ConversionProblem
{
    public static function inMultipartBody(): self
    {
        return new self('The request must carry the source file in a multipart part named "file".');
    }

    public function status(): int
    {
        return 422;
    }

    public function type(): string
    {
        return '/errors/missing-file';
    }

    public function title(): string
    {
        return 'No file was sent';
    }
}
