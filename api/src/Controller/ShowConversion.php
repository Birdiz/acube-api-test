<?php

declare(strict_types=1);

namespace App\Controller;

use App\Conversion\ConversionDocument;
use App\Repository\ConversionRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;

#[AsController]
final class ShowConversion
{
    public function __construct(
        private readonly ConversionRepository $conversions,
        private readonly ConversionDocument $document,
    ) {
    }

    public function __invoke(string $id): Response
    {
        $response = new JsonResponse($this->document->of($this->conversions->withId($id)));

        // A polled status that may be cached is a status that lies.
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
