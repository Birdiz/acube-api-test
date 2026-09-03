<?php

declare(strict_types=1);

namespace App\Message;

/** An id, not a copy: the worker reads the conversion's state when it runs. */
final readonly class RunConversion
{
    public function __construct(public string $conversionId)
    {
    }
}
