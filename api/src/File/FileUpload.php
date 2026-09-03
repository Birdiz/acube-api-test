<?php

declare(strict_types=1);

namespace App\File;

use App\Conversion\SourceFormat;
use App\Entity\File;
use App\File\Exception\EmptyFile;
use App\File\Exception\MissingFilePart;
use App\File\Exception\PartialUpload;
use App\File\Exception\UploadTooLarge;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

/**
 * Turns a multipart request into a stored file. PHP's error code is read
 * before the contents: a file PHP rejected has none worth reading.
 */
final class FileUpload
{
    public function __construct(
        #[Autowire('%env(int:FILE_MAX_SIZE_BYTES)%')]
        private readonly int $maxSizeBytes,
        #[Autowire('%kernel.project_dir%/var/uploads')]
        private readonly string $directory,
        private readonly Filesystem $filesystem,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function receive(Request $request): File
    {
        $upload = $this->attachment($request);

        $this->failOnPhpError($upload);

        $size = $this->size($upload);
        $format = SourceFormat::fromMimeType($this->detectMimeType($upload));

        $file = new File($this->filename($upload), $format, $size);

        // Bytes first: orphaned bytes are inert, a row pointing at none is not.
        $this->store($upload, $this->pathFor($file));

        $this->entityManager->persist($file);
        $this->entityManager->flush();

        return $file;
    }

    private function pathFor(File $file): string
    {
        return $this->directory.'/'.$file->id();
    }

    private function attachment(Request $request): UploadedFile
    {
        $upload = $request->files->get('file');

        if ($upload instanceof UploadedFile) {
            return $upload;
        }

        throw MissingFilePart::inMultipartBody();
    }

    private function failOnPhpError(UploadedFile $upload): void
    {
        match ($upload->getError()) {
            \UPLOAD_ERR_OK => null,
            // FORM_SIZE is the caller's own MAX_FILE_SIZE field: still a size breach.
            \UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE => throw UploadTooLarge::refusedByPhp($this->maxSizeBytes),
            \UPLOAD_ERR_PARTIAL => throw PartialUpload::wasReceived(),
            // A full disk or an unwritable temp dir is ours, not the caller's.
            default => throw new \RuntimeException($upload->getErrorMessage()),
        };
    }

    /** Arbitrary bytes of arbitrary length, so it is made printable and cut to size. */
    private function filename(UploadedFile $upload): string
    {
        $name = $upload->getClientOriginalName();

        if (!mb_check_encoding($name, 'UTF-8')) {
            $name = mb_convert_encoding($name, 'UTF-8', 'UTF-8');
        }

        return mb_substr($name, 0, File::MAX_FILENAME_LENGTH);
    }

    private function size(UploadedFile $upload): int
    {
        $size = $upload->getSize();

        if (false === $size) {
            throw new \RuntimeException('The size of the uploaded file could not be read.');
        }

        if (0 === $size) {
            throw EmptyFile::wasUploaded();
        }

        if ($size > $this->maxSizeBytes) {
            throw UploadTooLarge::forSize($size, $this->maxSizeBytes);
        }

        return $size;
    }

    /** From the bytes on disk. The filename and the declared type are claims. */
    private function detectMimeType(UploadedFile $upload): string
    {
        $detected = (new \finfo(\FILEINFO_MIME_TYPE))->file($upload->getPathname());

        if (false === $detected) {
            throw new \RuntimeException('The type of the uploaded file could not be determined.');
        }

        return $detected;
    }

    /** Creates the directory, streams the bytes, and throws on its own if either fails. */
    private function store(UploadedFile $upload, string $destination): void
    {
        $this->filesystem->copy($upload->getPathname(), $destination);
    }
}
