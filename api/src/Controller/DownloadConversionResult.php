<?php

declare(strict_types=1);

namespace App\Controller;

use ApiPlatform\Metadata\IriConverterInterface;
use App\Conversion\ConversionResult;
use App\Conversion\ConversionStatus;
use App\Conversion\ConversionUri;
use App\Conversion\Exception\ResultNotReady;
use App\Entity\Conversion;
use App\Repository\ConversionRepository;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;

#[AsController]
final readonly class DownloadConversionResult
{
    public function __construct(
        private ConversionRepository $conversions,
        private ConversionResult $result,
        private IriConverterInterface $iriConverter,
    ) {
    }

    public function __invoke(string $id): Response
    {
        $conversion = $this->conversions->withId($id);

        if (ConversionStatus::Done !== $conversion->status()) {
            throw ResultNotReady::forStatus($conversion->status(), $this->statusUrl($conversion));
        }

        // Read whole rather than streamed: a result is bounded by the upload it
        // came from, and unlike the status it never changes once it exists.
        return new Response(
            $this->result->contents($conversion),
            Response::HTTP_OK,
            [
                'Content-Type' => $conversion->targetFormat()->contentType(),
                'Content-Disposition' => $this->result->disposition($conversion),
            ],
        );
    }

    private function statusUrl(Conversion $conversion): string
    {
        // A null here would mean API Platform cannot address a resource it has
        // just persisted: ours to fix, never the caller's to work around.
        return $this->iriConverter->getIriFromResource(
            $conversion,
            context: ConversionUri::Status->iriContext(),
        ) ?? throw new \LogicException(\sprintf('Conversion %s has no status IRI.', $conversion->id()));
    }
}
