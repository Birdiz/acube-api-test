<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\File;
use App\Repository\FileRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;

/** What the API made of an upload, at the address its `Location` named. */
#[AsController]
final readonly class ShowFile
{
    public function __construct(private FileRepository $files)
    {
    }

    public function __invoke(string $id): JsonResponse
    {
        // No `no-store`, unlike a conversion's status: an upload is settled the
        // moment it is accepted, and nothing here changes afterwards.
        return new JsonResponse($this->document($this->files->withId($id)), Response::HTTP_OK);
    }

    /**
     * `format` is the one thing the caller could not already know: it was read
     * from the bytes, so it may not be the type they believed they sent. The
     * filename is echoed back exactly as received, and used for nothing else.
     *
     * @return array<string, string|int>
     */
    private function document(File $file): array
    {
        return [
            'id' => $file->id(),
            'filename' => $file->originalFilename(),
            'format' => $file->sourceFormat()->value,
            'size_bytes' => $file->sizeBytes(),
            'created_at' => $file->createdAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
