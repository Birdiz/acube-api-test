<?php

declare(strict_types=1);

namespace App\Conversion;

use App\Conversion\Exception\MalformedBody;
use App\Entity\Conversion;
use App\Entity\File;
use App\File\Exception\UnknownFile;
use App\Message\RunConversion;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * It reads the request itself because the order is part of the contract: the
 * path names what is being acted on, so an unknown file is answered before
 * the body.
 */
final class ConversionScheduler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $bus,
    ) {
    }

    public function schedule(Request $request, string $fileId): Conversion
    {
        $file = $this->entityManager->find(File::class, $fileId)
            ?? throw UnknownFile::withId($fileId);

        $conversion = new Conversion($file, TargetFormat::fromRequest($this->requestedFormat($request)));

        // One transaction: no job outlives a rolled-back conversion, and no
        // pending conversion is left without one.
        $this->entityManager->wrapInTransaction(function () use ($conversion): void {
            $this->entityManager->persist($conversion);
            $this->entityManager->flush();

            $this->bus->dispatch(new RunConversion($conversion->id()));
        });

        return $conversion;
    }

    /** A missing `format` becomes an empty string, which TargetFormat refuses. */
    private function requestedFormat(Request $request): string
    {
        try {
            $payload = $request->toArray();
        } catch (\JsonException|\UnexpectedValueException $invalid) {
            throw MalformedBody::isNotJson($invalid);
        }

        $requested = $payload['format'] ?? '';

        return \is_string($requested) ? $requested : '';
    }
}
