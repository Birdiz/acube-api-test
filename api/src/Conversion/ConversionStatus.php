<?php

declare(strict_types=1);

namespace App\Conversion;

/**
 * The lifecycle a conversion moves through. Only `Pending` is ever written at
 * request time; the worker owns the rest.
 */
enum ConversionStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
}
