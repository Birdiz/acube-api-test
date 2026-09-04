<?php

declare(strict_types=1);

namespace App\Tests\Api\Support;

use Psr\Container\ContainerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Stands in for the `messenger:consume conversions` worker, which a functional
 * test has no background process to run. Without it, "pending" and "done"
 * would be a race against a sleep rather than two states a test can order.
 */
final readonly class ConversionQueue
{
    private const string TRANSPORT = 'conversions';

    public function __construct(private ContainerInterface $container)
    {
    }

    public function drain(): void
    {
        $transport = $this->transport();
        $bus = $this->container->get(MessageBusInterface::class);

        // A job may queue follow-up work, so drain until the queue is empty.
        while ([] !== $envelopes = $transport->get()) {
            foreach ($envelopes as $envelope) {
                // ReceivedStamp stops the bus putting it back on the transport.
                $bus->dispatch($envelope->with(new ReceivedStamp(self::TRANSPORT)));
                $transport->ack($envelope);
            }
        }
    }

    private function transport(): InMemoryTransport
    {
        $id = 'messenger.transport.'.self::TRANSPORT;

        // A broken harness, not a failing expectation: throw, don't assert.
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
