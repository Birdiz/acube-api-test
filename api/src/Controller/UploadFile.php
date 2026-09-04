<?php

declare(strict_types=1);

namespace App\Controller;

use ApiPlatform\Metadata\IriConverterInterface;
use App\File\FileUpload;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;

#[AsController]
final readonly class UploadFile
{
    public function __construct(
        private FileUpload $upload,
        private IriConverterInterface $iriConverter,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $file = $this->upload->receive($request);

        return new JsonResponse(
            ['id' => $file->id()],
            Response::HTTP_CREATED,
            ['Location' => $this->iriConverter->getIriFromResource($file)],
        );
    }
}
