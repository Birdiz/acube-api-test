<?php

declare(strict_types=1);

namespace App\Conversion\Exception;

/**
 * Unparseable, so there is nothing to validate: a 400 rather than the 422 a
 * well-formed body with a bad field would get.
 */
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
