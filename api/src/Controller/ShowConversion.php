<?php

declare(strict_types=1);

namespace App\Controller;

use App\Conversion\ConversionDocument;
use App\Repository\ConversionRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;

#[AsController]
final readonly class ShowConversion
{
    public function __construct(
        private ConversionRepository $conversions,
        private ConversionDocument $document,
    ) {
    }

    public function __invoke(string $id): JsonResponse
    {
        $response = new JsonResponse($this->document->of($this->conversions->withId($id)));

        // A polled status that may be cached is a status that lies.
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
