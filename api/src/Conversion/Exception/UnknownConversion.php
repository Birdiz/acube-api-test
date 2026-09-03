<?php

declare(strict_types=1);

namespace App\Conversion\Exception;

final class UnknownConversion extends ConversionProblem
{
    public static function withId(string $conversionId): self
    {
        return new self(\sprintf('No conversion has the id "%s".', $conversionId));
    }

    public function status(): int
    {
        return 404;
    }

    public function type(): string
    {
        return '/errors/unknown-conversion';
    }

    public function title(): string
    {
        return 'Unknown conversion';
    }
}
