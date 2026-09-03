<?php

declare(strict_types=1);

namespace App\Tests\Api\Support;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

/**
 * Drives the conversion API over HTTP.
 *
 * This is the only place that knows the routes and how a request to each one is
 * shaped. It asserts nothing itself: it makes requests, hands back responses,
 * and notifies a listener about each one. The test case supplies that listener,
 * which is how invariants get checked the moment a response arrives without
 * this class knowing anything about assertions.
 */
final class ApiClient
{
    /** @var \Closure(Response): void */
    private readonly \Closure $onResponse;

    /**
     * @param (callable(Response): void)|null $onResponse called with every
     *        response as it arrives, before the caller sees it
     */
    public function __construct(
        private readonly KernelBrowser $browser,
        ?callable $onResponse = null,
    ) {
        $this->onResponse = null !== $onResponse
            ? $onResponse(...)
            : static function (Response $response): void {};
    }

    // ------------------------------------------------------------------ files

    /**
     * POST /api/files with a multipart body.
     *
     * $sentName and $sentMimeType let a caller lie about what it is uploading,
     * which is how "trust the bytes, not the client" gets verified. $error
     * reproduces a failure PHP itself would have recorded against the upload.
     */
    public function postFile(
        string $path,
        ?string $sentName = null,
        ?string $sentMimeType = null,
        ?int $error = null,
    ): Response {
        $upload = new UploadedFile(
            path: $path,
            originalName: $sentName ?? basename($path),
            mimeType: $sentMimeType,
            error: $error,
            test: true,
        );

        $this->browser->request('POST', '/api/files', files: ['file' => $upload]);

        return $this->capture();
    }

    /** POST /api/files with no `file` part at all. */
    public function postFileWithoutAttachment(): Response
    {
        $this->browser->request('POST', '/api/files');

        return $this->capture();
    }

    /**
     * Reproduces an upload that PHP threw away before the application ran.
     *
     * Past post_max_size, PHP discards the whole body: $_POST and $_FILES come
     * back empty and only Content-Length still says a file was sent.
     */
    public function postFileDroppedByPhp(int $announcedBytes): Response
    {
        $this->browser->request(
            'POST',
            '/api/files',
            server: [
                'CONTENT_TYPE' => 'multipart/form-data; boundary=----dropped',
                'CONTENT_LENGTH' => (string) $announcedBytes,
            ],
        );

        return $this->capture();
    }

    // ------------------------------------------------------------ conversions

    public function postConversion(string $fileId, mixed $body): Response
    {
        $this->browser->request(
            'POST',
            \sprintf('/api/files/%s/conversions', $fileId),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: \is_string($body) ? $body : json_encode($body, \JSON_THROW_ON_ERROR),
        );

        return $this->capture();
    }

    public function getConversion(string $conversionId): Response
    {
        return $this->get(\sprintf('/api/conversions/%s', $conversionId));
    }

    public function getConversionResult(string $conversionId): Response
    {
        return $this->get(\sprintf('/api/conversions/%s/result', $conversionId));
    }

    /** Follows a URL the API handed us, such as a Location header. */
    public function get(string $url): Response
    {
        $this->browser->request('GET', $url);

        return $this->capture();
    }

    // -------------------------------------------------------------- responses

    public function response(): Response
    {
        return $this->browser->getResponse();
    }

    private function capture(): Response
    {
        $response = $this->browser->getResponse();

        ($this->onResponse)($response);

        return $response;
    }
}
