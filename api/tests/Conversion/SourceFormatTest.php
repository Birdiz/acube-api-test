<?php

declare(strict_types=1);

namespace App\Tests\Conversion;

use App\Conversion\Exception\UnsupportedSourceFormat;
use App\Conversion\SourceFormat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[TestDox('SourceFormat')]
final class SourceFormatTest extends TestCase
{
    /** @return iterable<string, array{string, SourceFormat}> */
    public static function mimeTypes(): iterable
    {
        foreach (SourceFormat::cases() as $format) {
            foreach ($format->mimeTypes() as $mimeType) {
                yield $mimeType => [$mimeType, $format];
            }
        }
    }

    #[Test]
    #[DataProvider('mimeTypes')]
    #[TestDox('resolves $_dataName')]
    public function itResolvesEveryDeclaredMimeType(string $mimeType, SourceFormat $expected): void
    {
        self::assertSame($expected, SourceFormat::fromMimeType($mimeType));
    }

    #[Test]
    #[TestDox('refuses a type it cannot convert from')]
    public function itRefusesAnUnknownMimeType(): void
    {
        $this->expectException(UnsupportedSourceFormat::class);

        SourceFormat::fromMimeType('application/pdf');
    }

    #[Test]
    #[TestDox('names the refused type and the supported ones')]
    public function itExplainsWhatWasRefused(): void
    {
        try {
            SourceFormat::fromMimeType('application/pdf');
            self::fail('Expected an UnsupportedSourceFormat.');
        } catch (UnsupportedSourceFormat $problem) {
            self::assertSame(415, $problem->status());
            self::assertStringContainsString('application/pdf', $problem->getMessage());

            foreach (SourceFormat::cases() as $format) {
                self::assertStringContainsString($format->value, $problem->getMessage());
            }
        }
    }

    #[Test]
    #[TestDox('does not accept a bare ZIP as a spreadsheet')]
    public function itDoesNotAcceptABareZip(): void
    {
        // XLSX and ODS are ZIP containers, so this is the mistake to guard.
        $this->expectException(UnsupportedSourceFormat::class);

        SourceFormat::fromMimeType('application/zip');
    }

    #[Test]
    #[TestDox('gives every case at least one MIME type, and shares none')]
    public function itMapsEachCaseToDistinctMimeTypes(): void
    {
        $seen = [];

        foreach (SourceFormat::cases() as $format) {
            self::assertNotEmpty($format->mimeTypes(), \sprintf('%s must be detectable.', $format->name));

            foreach ($format->mimeTypes() as $mimeType) {
                self::assertArrayNotHasKey($mimeType, $seen, \sprintf(
                    '"%s" would resolve to both %s and %s.',
                    $mimeType,
                    $seen[$mimeType] ?? '',
                    $format->name,
                ));
                $seen[$mimeType] = $format->name;
            }
        }
    }
}
