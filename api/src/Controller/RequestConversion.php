<?php

declare(strict_types=1);

namespace App\Controller;

use ApiPlatform\Metadata\IriConverterInterface;
use App\Conversion\Exception\MalformedBody;
use App\Conversion\TargetFormat;
use App\Entity\Conversion;
use App\Entity\File;
use App\File\Exception\UnknownFile;
use App\Message\RunConversion;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsController]
final class RequestConversion
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $bus,
        private readonly IriConverterInterface $iriConverter,
    ) {
    }

    public function __invoke(Request $request, string $fileId): Response
    {
        // The path names what is being acted on, so it is answered before the body.
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

        return new JsonResponse(
            [
                'id' => $conversion->id(),
                'status' => $conversion->status()->value,
            ],
            Response::HTTP_ACCEPTED,
            ['Location' => $this->iriConverter->getIriFromResource($conversion)],
        );
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
