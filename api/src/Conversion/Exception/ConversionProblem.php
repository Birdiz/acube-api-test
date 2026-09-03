<?php

declare(strict_types=1);

namespace App\Conversion\Exception;

/**
 * A failure the caller caused, and can therefore be told about precisely.
 *
 * Everything needed to render an RFC 9457 problem document lives on the
 * exception, so the code that throws decides what the caller is told and there
 * is one place that turns these into responses. The exception message is the
 * problem's `detail`.
 *
 * A failure that is *not* one of these is ours, and is the only thing allowed
 * to become a 5xx.
 */
abstract class ConversionProblem extends \RuntimeException
{
    /** The HTTP status this problem is reported with. */
    abstract public function status(): int;

    /** Stable identifier for the kind of problem, for callers to branch on. */
    abstract public function type(): string;

    /** Short, human-readable summary of the kind of problem. */
    abstract public function title(): string;

    /**
     * Problem-specific members, added alongside the standard ones.
     *
     * This is what makes an error actionable rather than merely accurate.
     *
     * @return array<string, mixed>
     */
    public function extensions(): array
    {
        return [];
    }
}
