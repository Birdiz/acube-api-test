<?php

declare(strict_types=1);

namespace App\Conversion\Exception;

use App\Conversion\ConversionStatus;
use App\Conversion\SourceFormat;
use App\Conversion\TargetFormat;

/**
 * A failure the caller caused, carrying everything its error response needs so
 * that rendering happens in one place: the status, a stable `type` to branch
 * on, a title, the `detail` — and, where an answer is only actionable with
 * more, the extra members that make it so.
 *
 * One class rather than one per case: a subclass whose whole body is four
 * constant strings is a file to open, not a distinction to make. The named
 * constructors below are the vocabulary; the code that detects a problem is
 * still the code that decides what the caller is told.
 *
 * A failure that is *not* one of these is ours, and is the only thing allowed
 * to become a 5xx.
 */
final class ConversionProblem extends \RuntimeException
{
    /** @param array<string, mixed> $extensions */
    private function __construct(
        public readonly int $status,
        public readonly string $type,
        public readonly string $title,
        string $detail,
        public readonly array $extensions = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($detail, previous: $previous);
    }

    /** Unparseable, so there is nothing to validate: 400, not the 422 a bad field gets. */
    public static function malformedBody(?\Throwable $previous = null): self
    {
        return new self(
            400,
            '/errors/malformed-body',
            'Malformed request body',
            'The request body is not valid JSON.',
            previous: $previous,
        );
    }

    public static function unknownFile(string $fileId): self
    {
        return new self(
            404,
            '/errors/unknown-file',
            'Unknown file',
            \sprintf('No uploaded file has the id "%s".', $fileId),
        );
    }

    public static function unknownConversion(string $conversionId): self
    {
        return new self(
            404,
            '/errors/unknown-conversion',
            'Unknown conversion',
            \sprintf('No conversion has the id "%s".', $conversionId),
        );
    }

    /**
     * Not 404: the caller has this id from the 202, and being told it does not
     * exist invites restarting the flow. It carries the state instead, which is
     * the part the caller branches on — named `conversion_status` because
     * `status` is already the HTTP one.
     */
    public static function resultNotReady(ConversionStatus $status, string $statusUrl): self
    {
        // Same conflict, but waiting will not fix a failed job.
        $detail = match ($status) {
            ConversionStatus::Failed => \sprintf(
                'The conversion failed, so there will be no result. %s says what happened.',
                $statusUrl,
            ),
            default => \sprintf(
                'The conversion is still %s. Poll %s until it reports "%s".',
                $status->value,
                $statusUrl,
                ConversionStatus::Done->value,
            ),
        };

        return new self(
            409,
            '/errors/result-not-ready',
            'Result not ready',
            $detail,
            ['conversion_status' => $status->value, 'status_url' => $statusUrl],
        );
    }

    public static function unsupportedSourceFormat(string $mimeType): self
    {
        return new self(
            415,
            '/errors/unsupported-file-type',
            'Unsupported file type',
            \sprintf(
                'Files of type "%s" cannot be converted. Supported types are: %s.',
                $mimeType,
                implode(', ', array_column(SourceFormat::cases(), 'value')),
            ),
        );
    }

    /** The `supported_formats` are there because a 422 that does not say what *is* allowed makes the caller guess. */
    public static function unsupportedTargetFormat(string $requested): self
    {
        return new self(
            422,
            '/errors/unsupported-format',
            'Unsupported output format',
            \sprintf(
                'Cannot convert to "%s". Supported formats are: %s.',
                $requested,
                implode(', ', TargetFormat::values()),
            ),
            ['supported_formats' => TargetFormat::values()],
        );
    }

    public static function missingFilePart(): self
    {
        return new self(
            422,
            '/errors/missing-file',
            'No file was sent',
            'The request must carry the source file in a multipart part named "file".',
        );
    }

    /** The connection dropped mid-upload: the caller's to retry, not ours to fix. */
    public static function partialUpload(): self
    {
        return new self(
            422,
            '/errors/partial-upload',
            'Incomplete upload',
            'The upload ended before the whole file arrived. Send it again.',
        );
    }

    public static function emptyFile(): self
    {
        return new self(
            422,
            '/errors/empty-file',
            'Empty file',
            'The uploaded file is empty, so there is nothing to convert.',
        );
    }

    public static function uploadTooLarge(int $sizeBytes, int $maxBytes): self
    {
        return new self(
            413,
            '/errors/file-too-large',
            'File too large',
            \sprintf(
                'The file is %d bytes; the maximum accepted size is %d bytes.',
                $sizeBytes,
                $maxBytes,
            ),
        );
    }

    /** PHP refused the upload before it completed, so there is no size to name — only the limit. */
    public static function uploadRefusedByPhp(int $maxBytes): self
    {
        return new self(
            413,
            '/errors/file-too-large',
            'File too large',
            \sprintf(
                'The upload exceeded the server limit and was refused before it completed. '
                .'The maximum accepted size is %d bytes.',
                $maxBytes,
            ),
        );
    }
}
