<?php

declare(strict_types=1);

namespace App\Conversion;

/** A conversion answers at two addresses, so "its URL" is ambiguous until one is named. */
enum ConversionUri: string
{
    case Status = '/conversions/{id}';
    case Result = '/conversions/{id}/result';
}
