<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\Conversion\ConversionStatus;
use App\Conversion\SourceFormat;
use App\Conversion\TargetFormat;
use App\Entity\Conversion;
use App\Entity\File;
use App\Message\RunConversion;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * `failed` is the one state the API suite cannot reach: a functional test has no
 * worker, so `WorkerMessageFailedEvent` never fires there.
 *
 * Dispatched through the real dispatcher rather than by calling the listener, so
 * that Messenger's own retry listener gets to decide first — which is the thing
 * being relied on, and which a direct call would quietly skip.
 */
#[TestDox('A conversion whose job failed')]
final class FailedConversionListenerTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    private EventDispatcherInterface $dispatcher;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->dispatcher = self::getContainer()->get(EventDispatcherInterface::class);

        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    #[Test]
    #[TestDox('is recorded as failed once the retries are spent')]
    public function itRecordsAFailureWhenTheRetriesAreSpent(): void
    {
        $conversion = $this->queuedConversion();

        $this->dispatcher->dispatch($this->failureOf($conversion, new \RuntimeException('Disk full.'), retries: 3));

        self::assertSame(ConversionStatus::Failed, $conversion->status());
        self::assertNotNull($conversion->completedAt(), 'A failure is an ending, and endings have a time.');
    }

    #[Test]
    #[TestDox('is left alone while an attempt remains')]
    public function itLeavesAConversionAloneWhileItWillRetry(): void
    {
        $conversion = $this->queuedConversion();

        // No redeliveries yet, so Messenger's retry listener claims it first.
        $this->dispatcher->dispatch($this->failureOf($conversion, new \RuntimeException('Transient.')));

        self::assertSame(
            ConversionStatus::Pending,
            $conversion->status(),
            'Telling a caller a job is over while it is still being retried is a lie that resolves itself.',
        );
        self::assertNull($conversion->errorMessage());
    }

    #[Test]
    #[TestDox('explains itself without quoting the throwable')]
    public function itStoresACallerSafeReason(): void
    {
        $conversion = $this->queuedConversion();

        $this->dispatcher->dispatch($this->failureOf(
            $conversion,
            new \RuntimeException('SQLSTATE[HY000] at /app/var/data_test.db'),
            retries: 3,
        ));

        $reason = (string) $conversion->errorMessage();

        self::assertStringNotContainsString('/app/var', $reason, 'An error message is not a place to leak paths.');
        self::assertStringNotContainsString('SQLSTATE', $reason, 'Nor a place to leak SQL.');
        self::assertStringContainsString('RuntimeException', $reason, 'It should still say what broke.');
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

    /** @param int $retries how many redeliveries the job has already had; 3 is messenger.yaml's limit */
    private function failureOf(Conversion $conversion, \Throwable $cause, int $retries = 0): WorkerMessageFailedEvent
    {
        $envelope = new Envelope(new RunConversion($conversion->id()), [new RedeliveryStamp($retries)]);

        // Wrapped, because that is the only shape a real worker ever produces.
        return new WorkerMessageFailedEvent(
            $envelope,
            'conversions',
            new HandlerFailedException($envelope, [$cause]),
        );
    }
}
