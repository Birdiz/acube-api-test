<?php

declare(strict_types=1);

namespace App\Controller;

use ApiPlatform\Metadata\IriConverterInterface;
use App\Conversion\ConversionScheduler;
use App\Conversion\ConversionUri;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;

#[AsController]
final readonly class RequestConversion
{
    public function __construct(
        private ConversionScheduler $scheduler,
        private IriConverterInterface $iriConverter,
    ) {
    }

    public function __invoke(Request $request, string $fileId): JsonResponse
    {
        $conversion = $this->scheduler->schedule($request, $fileId);

        return new JsonResponse(
            [
                'id' => $conversion->id(),
                'status' => $conversion->status()->value,
            ],
            Response::HTTP_ACCEPTED,
            // Named rather than implied: a conversion has two item GETs.
            ['Location' => $this->iriConverter->getIriFromResource(
                $conversion,
                // Without this, IriConverter builds whichever item GET is declared first.
                context: ['item_uri_template' => ConversionUri::Status->value],
            )],
        );
    }
}
