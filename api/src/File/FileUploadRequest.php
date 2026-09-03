<?php

declare(strict_types=1);

namespace App\File;

use ApiPlatform\Metadata\ApiProperty;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** The multipart body of an upload: one part, named `file`, carrying the bytes. */
final class FileUploadRequest
{
    #[ApiProperty(openapiContext: ['type' => 'string', 'format' => 'binary'])]
    public ?UploadedFile $file = null;
}
