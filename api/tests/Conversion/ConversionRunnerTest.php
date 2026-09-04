<?php

declare(strict_types=1);

namespace App\Tests\Conversion;

use App\Conversion\ConversionResult;
use App\Conversion\ConversionRunner;
use App\Conversion\ConversionStatus;
use App\Conversion\SourceFormat;
use App\Conversion\TargetFormat;
use App\Entity\Conversion;
use App\Entity\File;
use App\Repository\ConversionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * `failed` is the one state the API suite cannot reach: a functional test has
 * no worker and a stub conversion has nothing to trip over. Here the result
 * store is pointed somewhere unwritable, which is the real shape of the
 * failure — the bytes could not be written.
 */
#[TestDox('A conversion whose job failed')]
final class ConversionRunnerTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    #[Test]
    #[TestDox('is recorded as failed, and says so without quoting the throwable')]
    public function itRecordsAFailureTheCallerCanRead(): void
    {
        $conversion = $this->queuedConversion();

        $this->expectException(IOException::class);

        try {
            $this->runnerWritingTo('/dev/null/results')->run($conversion->id());
        } finally {
            self::assertSame(ConversionStatus::Failed, $conversion->status());
            self::assertNotNull($conversion->completedAt(), 'A failure is an ending, and endings have a time.');

            $reason = (string) $conversion->errorMessage();
            self::assertStringNotContainsString('/dev/null', $reason, 'An error message is not a place to leak paths.');
            self::assertStringContainsString('IOException', $reason, 'It should still say what broke.');
        }
    }

    private function queuedConversion(): Conversion
    {
        $file = new File('quarterly.csv', SourceFormat::Csv, 1024);
        $conversion = new Conversion($file, TargetFormat::Xml);

        $this->entityManager->persist($file);
        $this->entityManager->persist($conversion);
        $this->entityManager->flush();

        return $conversion;
    }

    private function runnerWritingTo(string $directory): ConversionRunner
    {
        return new ConversionRunner(
            self::getContainer()->get(ConversionRepository::class),
            new ConversionResult($directory, new Filesystem()),
            self::getContainer()->get(SerializerInterface::class),
            $this->entityManager,
        );
    }
}
