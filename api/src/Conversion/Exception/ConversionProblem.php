<?php

declare(strict_types=1);

namespace App\Conversion\Exception;

/**
 * A failure the caller caused, carrying everything its error response needs so
 * that rendering happens in one place. The message becomes the `detail`.
 *
 * A failure that is *not* one of these is ours, and is the only thing allowed
 * to become a 5xx.
 */
abstract class ConversionProblem extends \RuntimeException
{
    abstract public function status(): int;

    /** Stable identifier for callers to branch on. */
    abstract public function type(): string;

    abstract public function title(): string;

    /**
     * Extra members, which is what makes an error actionable rather than
     * merely accurate.
     *
     * @return array<string, mixed>
     */
    public function extensions(): array
    {
        return [];
    }
}
