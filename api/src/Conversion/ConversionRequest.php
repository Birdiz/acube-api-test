<?php

declare(strict_types=1);

namespace App\Conversion;

/**
 * The body of a conversion request. It documents the shape; the value is
 * checked by {@see TargetFormat::fromRequest()}, whose refusal lists what is
 * supported — which a validation violation could not.
 */
final class ConversionRequest
{
    public TargetFormat $format;
}
