<?php

declare(strict_types=1);

namespace App\File;

use App\Conversion\SourceFormat;
use App\Entity\File;
use App\File\Exception\EmptyFile;
use App\File\Exception\MissingFilePart;
use App\File\Exception\PartialUpload;
use App\File\Exception\UploadTooLarge;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
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
    ) {
    }

    public function receive(Request $request): File
    {
        $upload = $this->attachment($request);

        $this->failOnPhpError($upload);

        $size = $this->size($upload);
        $format = SourceFormat::fromMimeType($this->detectMimeType($upload));

        $file = new File($this->filename($upload), $format, $size);

        $this->ensureDirectoryExists();
        $this->streamToStorage($upload, $this->pathFor($file));

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

        // Past post_max_size PHP discards the body, so $_FILES is empty and
        // only Content-Length still says a file was sent.
        $announced = (int) $request->headers->get('Content-Length', '0');

        if ($announced > $this->postMaxSizeBytes()) {
            throw UploadTooLarge::discardedByPhp($announced, $this->maxSizeBytes);
        }

        throw MissingFilePart::inMultipartBody();
    }

    /** An unset or non-positive `post_max_size` means PHP accepts any body. */
    private function postMaxSizeBytes(): int
    {
        $configured = trim((string) ini_get('post_max_size'));
        $amount = (int) $configured;

        if ($amount <= 0) {
            return \PHP_INT_MAX;
        }

        return $amount * match (strtolower(substr($configured, -1))) {
            'g' => 1024 ** 3,
            'm' => 1024 ** 2,
            'k' => 1024,
            default => 1,
        };
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

    /** A filesystem copy: the bytes never pass through PHP's memory. */
    private function streamToStorage(UploadedFile $upload, string $destination): void
    {
        if (!copy($upload->getPathname(), $destination)) {
            throw new \RuntimeException(\sprintf('Could not store the upload at "%s".', $destination));
        }
    }

    private function ensureDirectoryExists(): void
    {
        if (is_dir($this->directory)) {
            return;
        }

        if (!mkdir($this->directory, 0o775, true) && !is_dir($this->directory)) {
            throw new \RuntimeException(\sprintf('Could not create "%s".', $this->directory));
        }
    }
}
