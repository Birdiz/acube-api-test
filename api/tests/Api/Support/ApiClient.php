<?php

declare(strict_types=1);

namespace App\Tests\Api\Support;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

/**
 * The only place that knows the routes and how each request is shaped.
 *
 * It asserts nothing: the test case supplies a listener, which is how
 * invariants are checked as each response arrives without this class knowing
 * anything about assertions.
 */
final class ApiClient
{
    /** @var \Closure(Response): void */
    private readonly \Closure $onResponse;

    /** @param callable(Response): void $onResponse before the caller sees it */
    public function __construct(
        private readonly KernelBrowser $browser,
        callable $onResponse,
    ) {
        $this->onResponse = $onResponse(...);
    }

    /**
     * $sentName and $sentMimeType let a caller lie about what it is uploading,
     * which is how "trust the bytes, not the client" gets verified. $error
     * reproduces a failure PHP itself would have recorded.
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

    public function postFileWithoutAttachment(): Response
    {
        $this->browser->request('POST', '/api/files');

        return $this->capture();
    }

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

    public function get(string $url): Response
    {
        $this->browser->request('GET', $url);

        return $this->capture();
    }

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
