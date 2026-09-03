<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Controller\RequestConversion;
use App\Conversion\ConversionRequest;
use App\Conversion\ConversionStatus;
use App\Conversion\TargetFormat;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Ulid;

/** Written before the job is queued, so the address in the 202 resolves at once. */
#[ORM\Entity]
#[ApiResource(
    operations: [
        new Post(
            uriTemplate: '/files/{fileId}/conversions',
            status: Response::HTTP_ACCEPTED,
            controller: RequestConversion::class,
            input: ConversionRequest::class,
            inputFormats: ['json' => ['application/json']],
            // As on File: the controller decides the outcome and writes the body.
            deserialize: false,
            validate: false,
            write: false,
            read: false,
        ),
    ],
)]
class Conversion
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private readonly string $id;

    #[ORM\ManyToOne(targetEntity: File::class)]
    #[ORM\JoinColumn(nullable: false)]
    private readonly File $file;

    #[ORM\Column(type: 'string', length: 16, enumType: TargetFormat::class)]
    private readonly TargetFormat $targetFormat;

    #[ORM\Column(type: 'string', length: 16, enumType: ConversionStatus::class)]
    private ConversionStatus $status;

    #[ORM\Column(type: 'string', length: 1024, nullable: true)]
    private ?string $resultPath = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private readonly \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    public function __construct(File $file, TargetFormat $targetFormat)
    {
        $this->id = (string) new Ulid();
        $this->file = $file;
        $this->targetFormat = $targetFormat;
        $this->status = ConversionStatus::Pending;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function status(): ConversionStatus
    {
        return $this->status;
    }
}
