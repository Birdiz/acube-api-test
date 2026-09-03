<?php

declare(strict_types=1);

namespace App\Conversion\Exception;

/** Unparseable, so there is nothing to validate: 400, not the 422 a bad field gets. */
final class MalformedBody extends ConversionProblem
{
    public static function isNotJson(?\Throwable $previous = null): self
    {
        return new self('The request body is not valid JSON.', previous: $previous);
    }

    public function status(): int
    {
        return 400;
    }

    public function type(): string
    {
        return '/errors/malformed-body';
    }

    public function title(): string
    {
        return 'Malformed request body';
    }
}
