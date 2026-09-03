<?php

declare(strict_types=1);

namespace App\Tests\Api\Support;

use Psr\Container\ContainerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Stands in for the `messenger:consume conversions` worker.
 *
 * A functional test has no background process, so without this "pending" and
 * "done" would be a race against a sleep instead of two states a test can put
 * in order. Keeping it in one class means a different queueing choice touches
 * one file, not every test.
 */
final class ConversionQueue
{
    public const string TRANSPORT = 'conversions';

    public function __construct(private readonly ContainerInterface $container)
    {
    }

    /** Runs every queued conversion job to completion. */
    public function drain(): void
    {
        $transport = $this->transport();
        $bus = $this->container->get(MessageBusInterface::class);

        // A job may legitimately queue follow-up work, so keep going until the
        // queue is empty rather than draining a single batch.
        while ([] !== $envelopes = $transport->get()) {
            foreach ($envelopes as $envelope) {
                // ReceivedStamp tells the bus to handle the message here
                // instead of putting it back on the transport.
                $bus->dispatch($envelope->with(new ReceivedStamp(self::TRANSPORT)));
                $transport->ack($envelope);
            }
        }
    }

    private function transport(): InMemoryTransport
    {
        $id = 'messenger.transport.'.self::TRANSPORT;

        // A missing transport is a broken harness, not a failing expectation,
        // so this throws rather than asserting.
        if (!$this->container->has($id)) {
            throw new \LogicException(\sprintf(
                'Conversion jobs are expected to be queued on the "%s" transport.',
                self::TRANSPORT,
            ));
        }

        $transport = $this->container->get($id);

        if (!$transport instanceof InMemoryTransport) {
            throw new \LogicException(\sprintf(
                'The "%s" transport must be in-memory under when@test so it can be drained; got %s.',
                self::TRANSPORT,
                get_debug_type($transport),
            ));
        }

        return $transport;
    }
}
