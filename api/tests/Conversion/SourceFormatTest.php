<?php

declare(strict_types=1);

namespace App\Tests\Conversion;

use App\Conversion\Exception\ConversionProblem;
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
        $this->expectException(ConversionProblem::class);

        SourceFormat::fromMimeType('application/pdf');
    }

    #[Test]
    #[TestDox('names the refused type and the supported ones')]
    public function itExplainsWhatWasRefused(): void
    {
        try {
            SourceFormat::fromMimeType('application/pdf');
            self::fail('Expected the unsupported type to be refused.');
        } catch (ConversionProblem $problem) {
            self::assertSame(415, $problem->status);
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
        $this->expectException(ConversionProblem::class);

        SourceFormat::fromMimeType('application/zip');
    }

    #[Test]
    #[TestDox('maps each case to its published media type')]
    public function itMapsEachCaseToItsPublishedMediaType(): void
    {
        // The only place these strings are written out. Fixtures and the
        // validator both read the enum, so this is what stops a valid-but-wrong
        // media type — ODS mapped to the ODT string, say — going unnoticed.
        self::assertSame(['text/csv'], SourceFormat::Csv->mimeTypes());
        self::assertSame(['application/json'], SourceFormat::Json->mimeTypes());
        self::assertSame(
            ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            SourceFormat::Xlsx->mimeTypes(),
        );
        self::assertSame(
            ['application/vnd.oasis.opendocument.spreadsheet'],
            SourceFormat::Ods->mimeTypes(),
        );
    }

    #[Test]
    #[TestDox('shares no MIME type between cases')]
    public function itMapsEachCaseToDistinctMimeTypes(): void
    {
        $seen = [];

        foreach (SourceFormat::cases() as $format) {
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
