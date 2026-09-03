<?php

declare(strict_types=1);

namespace App\Controller;

use App\File\FileUpload;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;

/** POST /api/files */
#[AsController]
final class UploadFile
{
    public function __construct(
        private readonly FileUpload $upload,
        private readonly EntityManagerInterface $entityManager,
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
            ['Location' => '/api/files/'.$file->id()],
        );
    }
}
