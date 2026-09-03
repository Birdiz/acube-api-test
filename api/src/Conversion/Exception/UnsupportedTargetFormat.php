<?php

declare(strict_types=1);

namespace App\Conversion\Exception;

use App\Conversion\TargetFormat;

/** The caller asked for an output format we do not produce. */
final class UnsupportedTargetFormat extends ConversionProblem
{
    public static function forRequested(string $requested): self
    {
        return new self(\sprintf(
            'Cannot convert to "%s". Supported formats are: %s.',
            $requested,
            implode(', ', TargetFormat::values()),
        ));
    }

    public function status(): int
    {
        return 422;
    }

    public function type(): string
    {
        return '/errors/unsupported-format';
    }

    public function title(): string
    {
        return 'Unsupported output format';
    }

    /** A 422 that does not say what *is* allowed makes the caller guess. */
    public function extensions(): array
    {
        return ['supported_formats' => TargetFormat::values()];
    }
}
