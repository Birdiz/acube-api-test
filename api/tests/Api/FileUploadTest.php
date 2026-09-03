<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Conversion\SourceFormat;
use App\Tests\Api\Fixture\SampleFile;
use App\Tests\Api\Support\ApiAssert;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /api/files — hand the file over, get a handle back.
 *
 * Upload is its own step. The customer's file is unknown in shape and size, so
 * it has to be received and validated *before* anything is promised about
 * converting it: a request that is going to be rejected should be rejected
 * while the customer is still holding the connection, not two minutes later.
 */
#[TestDox('POST /api/files')]
final class FileUploadTest extends ApiTestCase
{

    /** @return iterable<string, array{string}> */
    public static function supportedSourceFiles(): iterable
    {
        foreach (SourceFormat::cases() as $format) {
            yield strtoupper($format->value) => [SampleFile::{$format->value}()];
        }
    }

    #[Test]
    #[DataProvider('supportedSourceFiles')]
    #[TestDox('accepts a $_dataName file with 201, an id and a Location')]
    public function itAcceptsEverySupportedSourceType(string $path): void
    {
        $response = $this->api->postFile($path);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $body = ApiAssert::json($response);
        self::assertArrayHasKey('id', $body, 'The customer needs an id to hang a conversion off.');
        self::assertIsString($body['id']);
        ApiAssert::opaqueId($body['id']);

        ApiAssert::locationMatches($response, '/api/files/{id}', $body['id']);
    }

    #[Test]
    #[TestDox('rejects an unsupported type with 415')]
    public function itRejectsAnUnsupportedFileType(): void
    {
        $response = $this->api->postFile(SampleFile::pdf());

        $problem = ApiAssert::problem($response, Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        self::assertStringContainsStringIgnoringCase(
            'pdf',
            $problem['detail'],
            'The error should name the type that was refused, so the caller can fix it.',
        );
    }

    #[Test]
    #[TestDox('detects the type from the bytes, not the filename')]
    public function itIgnoresTheFilenameWhenDetectingTheType(): void
    {
        // A PDF wearing a .csv name. Trusting the extension here would mean
        // queueing a two-minute job that is guaranteed to fail at the end.
        $response = $this->api->postFile(SampleFile::pdf(), sentName: 'quarterly-report.csv');

        ApiAssert::problem($response, Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
    }

    #[Test]
    #[TestDox('detects the type from the bytes, not the declared Content-Type')]
    public function itIgnoresTheClientDeclaredMimeType(): void
    {
        $response = $this->api->postFile(SampleFile::pdf(), sentName: 'data.csv', sentMimeType: 'text/csv');

        ApiAssert::problem($response, Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
    }

    #[Test]
    #[TestDox('rejects a bare ZIP that is neither XLSX nor ODS')]
    public function itRejectsAZipThatIsNotASpreadsheet(): void
    {
        // XLSX and ODS are ZIP containers; "it unzips" is not good enough.
        $response = $this->api->postFile(SampleFile::zip(), sentName: 'books.xlsx');

        ApiAssert::problem($response, Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
    }

    #[Test]
    #[TestDox('accepts a file sitting exactly on the size limit')]
    public function itAcceptsAFileAtTheLimit(): void
    {
        $this->api->postFile(SampleFile::csvOfSize($this->maxUploadBytes()));

        self::assertResponseStatusCodeSame(
            Response::HTTP_CREATED,
            'The limit is inclusive: a file of exactly the maximum size is valid.',
        );
    }

    #[Test]
    #[TestDox('rejects a file one byte over the limit with 413')]
    public function itRejectsAFileOverTheLimit(): void
    {
        $response = $this->api->postFile(SampleFile::csvOfSize($this->maxUploadBytes() + 1));

        $problem = ApiAssert::problem($response, Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        self::assertStringContainsString(
            (string) $this->maxUploadBytes(),
            $problem['detail'],
            'The error should state the limit, so the caller knows what to aim for.',
        );
    }

    #[Test]
    #[TestDox('reports a file PHP itself refused as 413, not a crash')]
    public function itMapsPhpsOwnSizeErrorToAClientError(): void
    {
        // The app limit is pinned to PHP's, so PHP may get there first — and
        // then the temp file is empty or partial. Reading it before checking
        // the error code is how this becomes a 500.
        $response = $this->api->postFile(SampleFile::csv(), error: \UPLOAD_ERR_INI_SIZE);

        $problem = ApiAssert::problem($response, Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        self::assertStringContainsString((string) $this->maxUploadBytes(), $problem['detail']);
    }

    #[Test]
    #[TestDox('reports a truncated upload as 422, not a crash')]
    public function itMapsAPartialUploadToAClientError(): void
    {
        // The connection dropped mid-upload. Nothing is wrong on our side.
        $response = $this->api->postFile(SampleFile::csv(), error: \UPLOAD_ERR_PARTIAL);

        ApiAssert::problem($response, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    #[Test]
    #[TestDox('rejects an empty file with 422')]
    public function itRejectsAnEmptyFile(): void
    {
        // Zero bytes is well-formed as a request and useless as a job.
        $response = $this->api->postFile(SampleFile::empty());

        ApiAssert::problem($response, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    #[Test]
    #[TestDox('rejects a request with no file part with 422')]
    public function itRejectsARequestWithoutAFile(): void
    {
        $response = $this->api->postFileWithoutAttachment();

        ApiAssert::problem($response, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    #[Test]
    #[TestDox('gives every upload its own id')]
    public function itGivesEveryUploadItsOwnId(): void
    {
        $first = $this->uploadFile(SampleFile::csv());
        $second = $this->uploadFile(SampleFile::csv());

        self::assertNotSame($first, $second, 'Two uploads of the same bytes are still two files.');
    }
}
