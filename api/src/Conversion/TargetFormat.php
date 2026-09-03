<?php

declare(strict_types=1);

namespace App\Conversion;

use App\Conversion\Exception\UnsupportedTargetFormat;

/** A file format the API can convert to. */
enum TargetFormat: string
{
    case Json = 'json';
    case Xml = 'xml';

    /** What the converted file is served as. */
    public function contentType(): string
    {
        return match ($this) {
            self::Json => 'application/json',
            self::Xml => 'application/xml',
        };
    }

    /**
     * Resolves what a caller asked for, case-insensitively: "XML" is as
     * reasonable a thing to send as "xml".
     *
     * @throws UnsupportedTargetFormat so the couple is refused at request time
     *         rather than by a job that fails two minutes later
     */
    public static function fromRequest(string $requested): self
    {
        return self::tryFrom(strtolower($requested))
            ?? throw UnsupportedTargetFormat::forRequested($requested);
    }

    /** @return non-empty-list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
