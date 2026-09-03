<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Controller\UploadFile;
use App\Conversion\SourceFormat;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Ulid;

/**
 * A source file that has been received and validated. The bytes live on disk
 * under the id, so where they are is derived rather than stored: a path
 * written into a row would be an absolute path from whichever container
 * happened to write it.
 */
#[ORM\Entity]
#[ApiResource(
    operations: [
        new Post(
            uriTemplate: '/files',
            status: Response::HTTP_CREATED,
            inputFormats: ['multipart' => ['multipart/form-data']],
            controller: UploadFile::class,
            // The controller answers with a Response of its own: a multipart
            // upload has nothing for the serializer to deserialize, and the
            // 201 body is written by hand.
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

    /**
     * Kept for the caller's benefit only. It is never used to build a storage
     * path, and never to decide what the file is.
     */
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
}
