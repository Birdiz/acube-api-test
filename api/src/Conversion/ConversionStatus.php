<?php

declare(strict_types=1);

namespace App\Conversion;

/** Only `Pending` is written at request time; the worker owns the rest. */
enum ConversionStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Done = 'done';
    case Failed = 'failed';
}
