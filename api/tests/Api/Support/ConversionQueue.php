<?php

declare(strict_types=1);

namespace App\Tests\Api\Support;

use Symfony\Bundle\FrameworkBundle\Test\TestContainer;
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

    // Typed as the test container, not ContainerInterface: reaching services
    // production keeps private is exactly what it is for, and saying so is what
    // makes these fetches legible to a reader and to static analysis.
    public function __construct(private TestContainer $container)
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
        // A missing id is the container's own error to report. What it cannot
        // say is that the transport must be drainable, so that is the guard
        // left here — a broken harness, not a failing expectation.
        $transport = $this->container->get('messenger.transport.'.self::TRANSPORT);

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
