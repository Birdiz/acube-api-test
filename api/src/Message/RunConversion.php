<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Carries an id and nothing else: the worker reads the current state of the
 * conversion when it runs it, rather than a copy of what it looked like when
 * it was queued.
 */
final readonly class RunConversion
{
    public function __construct(public string $conversionId)
    {
    }
}
