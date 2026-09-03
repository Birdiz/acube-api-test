<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Controller\UploadFile;
use App\Conversion\SourceFormat;
use App\File\FileUploadRequest;
use App\Repository\FileRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Ulid;

/**
 * Where the bytes live is derived from the id rather than stored: a path in a
 * row would be an absolute path from whichever container wrote it.
 */
#[ORM\Entity(repositoryClass: FileRepository::class)]
#[ApiResource(
    operations: [
        new Post(
            uriTemplate: '/files',
            status: Response::HTTP_CREATED,
            inputFormats: ['multipart' => ['multipart/form-data']],
            controller: UploadFile::class,
            input: FileUploadRequest::class,
            // The controller writes its own Response: nothing here to deserialize.
            deserialize: false,
            validate: false,
            write: false,
            read: false,
        ),
    ],
)]
class File
{
    /** The column's width, so the value and its storage cannot drift apart. */
    public const int MAX_FILENAME_LENGTH = 255;

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private readonly string $id;

    /** For the caller's benefit only: never used to locate or identify the file. */
    #[ORM\Column(type: 'string', length: self::MAX_FILENAME_LENGTH)]
    private readonly string $originalFilename;

    #[ORM\Column(type: 'string', length: 16, enumType: SourceFormat::class)]
    private readonly SourceFormat $sourceFormat;

    #[ORM\Column(type: 'integer')]
    private readonly int $sizeBytes;

    #[ORM\Column(type: 'datetime_immutable')]
    private readonly \DateTimeImmutable $createdAt;

    public function __construct(
        string $originalFilename,
        SourceFormat $sourceFormat,
        int $sizeBytes,
    ) {
        $this->id = (string) new Ulid();
        $this->originalFilename = $originalFilename;
        $this->sourceFormat = $sourceFormat;
        $this->sizeBytes = $sizeBytes;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function originalFilename(): string
    {
        return $this->originalFilename;
    }

    public function sourceFormat(): SourceFormat
    {
        return $this->sourceFormat;
    }
}
