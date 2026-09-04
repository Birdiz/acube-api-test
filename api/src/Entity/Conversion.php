<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use App\Controller\DownloadConversionResult;
use App\Controller\RequestConversion;
use App\Controller\ShowConversion;
use App\Conversion\ConversionRequest;
use App\Conversion\ConversionUri;
use App\Conversion\ConversionStatus;
use App\Conversion\TargetFormat;
use App\Repository\ConversionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Ulid;

/** Written before the job is queued, so the address in the 202 resolves at once. */
#[ORM\Entity(repositoryClass: ConversionRepository::class)]
#[ApiResource(
    operations: [
        new Post(
            uriTemplate: '/files/{fileId}/conversions',
            // Named, or the generated docs advertise a parameter called `id`
            // and Swagger UI sends `{fileId}` through unsubstituted.
            uriVariables: [
                'fileId' => new Link(fromClass: File::class, identifiers: ['id']),
            ],
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
        // Status first: an IRI resolves to the first item GET declared.
        new Get(
            uriTemplate: ConversionUri::Status->value,
            controller: ShowConversion::class,
            read: false,
            serialize: false,
        ),
        new Get(
            uriTemplate: ConversionUri::Result->value,
            controller: DownloadConversionResult::class,
            read: false,
            serialize: false,
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

    public function file(): File
    {
        return $this->file;
    }

    public function targetFormat(): TargetFormat
    {
        return $this->targetFormat;
    }

    public function status(): ConversionStatus
    {
        return $this->status;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function completedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function errorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function markProcessing(): void
    {
        $this->status = ConversionStatus::Processing;
    }

    public function markDone(): void
    {
        $this->status = ConversionStatus::Done;
        $this->completedAt = new \DateTimeImmutable();
    }

    public function markFailed(string $reason): void
    {
        $this->status = ConversionStatus::Failed;
        $this->errorMessage = $reason;
        $this->completedAt = new \DateTimeImmutable();
    }
}
