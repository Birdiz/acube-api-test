<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Message\RunConversion;
use App\Repository\ConversionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;

/**
 * Only a job out of retries is worth writing down: flipping the status on each
 * attempt would tell a caller polling mid-backoff that a job is over when it is
 * about to succeed. The default priority is what makes that work — Messenger
 * decides at 100, so `willRetry()` is final by the time this runs.
 */
#[AsEventListener(event: WorkerMessageFailedEvent::class)]
final readonly class FailedConversionListener
{
    public function __construct(
        private ConversionRepository $conversions,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(WorkerMessageFailedEvent $event): void
    {
        $message = $event->getEnvelope()->getMessage();

        if ($event->willRetry() || !$message instanceof RunConversion) {
            return;
        }

        // Read back by a caller, so it names no paths and no SQL; the throwable
        // itself goes to the log and the failed transport.
        $this->conversions->withId($message->conversionId)->markFailed(\sprintf(
            'The conversion could not be completed (%s).',
            (new \ReflectionClass($this->cause($event->getThrowable())))->getShortName(),
        ));

        $this->entityManager->flush();
    }

    /** Messenger wraps whatever the handler threw, and the wrapper's name names nothing. */
    private function cause(\Throwable $throwable): \Throwable
    {
        if ($throwable instanceof HandlerFailedException) {
            return $throwable->getPrevious() ?? $throwable;
        }

        return $throwable;
    }
}
