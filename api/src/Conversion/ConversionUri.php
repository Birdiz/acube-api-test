<?php

declare(strict_types=1);

namespace App\Conversion;

/** A conversion answers at two addresses, so "its URL" is ambiguous until one is named. */
enum ConversionUri: string
{
    case Status = '/conversions/{id}';
    case Result = '/conversions/{id}/result';

    /**
     * Without this, IriConverter builds whichever operation is declared first.
     *
     * @return array{item_uri_template: string}
     */
    public function iriContext(): array
    {
        return ['item_uri_template' => $this->value];
    }
}
