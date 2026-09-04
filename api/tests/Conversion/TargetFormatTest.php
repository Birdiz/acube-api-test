<?php

declare(strict_types=1);

namespace App\Tests\Conversion;

use App\Conversion\Exception\ConversionProblem;
use App\Conversion\TargetFormat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[TestDox('TargetFormat')]
final class TargetFormatTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function spellings(): iterable
    {
        yield 'lower case' => ['xml'];
        yield 'upper case' => ['XML'];
        yield 'mixed case' => ['XmL'];
    }

    #[Test]
    #[DataProvider('spellings')]
    #[TestDox('accepts $_dataName')]
    public function itResolvesAFormatWhateverTheCase(string $requested): void
    {
        self::assertSame(TargetFormat::Xml, TargetFormat::fromRequest($requested));
    }

    #[Test]
    #[TestDox('refuses a format it does not produce')]
    public function itRefusesAnUnknownFormat(): void
    {
        $this->expectException(ConversionProblem::class);

        TargetFormat::fromRequest('pdf');
    }

    #[Test]
    #[TestDox('reports an unsupported format as an actionable 422')]
    public function itExplainsWhatIsSupported(): void
    {
        try {
            TargetFormat::fromRequest('yaml');
            self::fail('Expected the unsupported format to be refused.');
        } catch (ConversionProblem $problem) {
            self::assertSame(422, $problem->status);
            self::assertStringContainsString('yaml', $problem->getMessage());
            self::assertSame(
                ['supported_formats' => ['json', 'xml']],
                $problem->extensions,
                'The caller must not have to guess what is allowed.',
            );
        }
    }

    #[Test]
    #[TestDox('serves each format as its own content type')]
    public function itServesEachFormatAsItsOwnContentType(): void
    {
        // Written out rather than derived: this is what goes on the wire.
        self::assertSame('application/json', TargetFormat::Json->contentType());
        self::assertSame('application/xml', TargetFormat::Xml->contentType());
    }

    #[Test]
    #[TestDox('lists exactly the formats the brief asks for')]
    public function itSupportsExactlyJsonAndXml(): void
    {
        self::assertSame(['json', 'xml'], TargetFormat::values());
    }
}
