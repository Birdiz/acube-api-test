<?php

declare(strict_types=1);

namespace App\Controller;

use ApiPlatform\Metadata\IriConverterInterface;
use App\Conversion\ConversionScheduler;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;

#[AsController]
final class RequestConversion
{
    public function __construct(
        private readonly ConversionScheduler $scheduler,
        private readonly IriConverterInterface $iriConverter,
    ) {
    }

    public function __invoke(Request $request, string $fileId): Response
    {
        $conversion = $this->scheduler->schedule($request, $fileId);

        return new JsonResponse(
            [
                'id' => $conversion->id(),
                'status' => $conversion->status()->value,
            ],
            Response::HTTP_ACCEPTED,
            ['Location' => $this->iriConverter->getIriFromResource($conversion)],
        );
    }
}
