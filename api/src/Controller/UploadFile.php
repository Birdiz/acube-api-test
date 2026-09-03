<?php

declare(strict_types=1);

namespace App\Controller;

use ApiPlatform\Metadata\IriConverterInterface;
use App\File\FileUpload;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;

#[AsController]
final class UploadFile
{
    public function __construct(
        private readonly FileUpload $upload,
        private readonly EntityManagerInterface $entityManager,
        private readonly IriConverterInterface $iriConverter,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $file = $this->upload->receive($request);

        $this->entityManager->persist($file);
        $this->entityManager->flush();

        return new JsonResponse(
            ['id' => $file->id()],
            Response::HTTP_CREATED,
            ['Location' => $this->iriConverter->getIriFromResource($file)],
        );
    }
}
