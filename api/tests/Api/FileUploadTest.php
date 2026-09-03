<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Api\Fixture\SampleFile;
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
        yield 'CSV' => [SampleFile::csv()];
        yield 'JSON' => [SampleFile::json()];
        yield 'XLSX' => [SampleFile::xlsx()];
        yield 'ODS' => [SampleFile::ods()];
    }

    #[Test]
    #[DataProvider('supportedSourceFiles')]
    #[TestDox('accepts a $_dataName file with 201, an id and a Location')]
    public function itAcceptsEverySupportedSourceType(string $path): void
    {
        $this->postFile($path);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $body = $this->responseBody();
        self::assertArrayHasKey('id', $body, 'The customer needs an id to hang a conversion off.');
        self::assertIsString($body['id']);
        $this->assertIdIsOpaqueAndStable($body['id']);

        $this->assertLocationMatches('/api/files/{id}', $body['id']);
    }

    #[Test]
    #[TestDox('rejects an unsupported type with 415')]
    public function itRejectsAnUnsupportedFileType(): void
    {
        $this->postFile(SampleFile::pdf());

        $problem = $this->assertProblemResponse(Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
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
        $this->postFile(SampleFile::pdf(), sentName: 'quarterly-report.csv');

        $this->assertProblemResponse(Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
    }

    #[Test]
    #[TestDox('detects the type from the bytes, not the declared Content-Type')]
    public function itIgnoresTheClientDeclaredMimeType(): void
    {
        $this->postFile(SampleFile::pdf(), sentName: 'data.csv', sentMimeType: 'text/csv');

        $this->assertProblemResponse(Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
    }

    #[Test]
    #[TestDox('rejects a bare ZIP that is neither XLSX nor ODS')]
    public function itRejectsAZipThatIsNotASpreadsheet(): void
    {
        // XLSX and ODS are ZIP containers; "it unzips" is not good enough.
        $this->postFile(SampleFile::zip(), sentName: 'books.xlsx');

        $this->assertProblemResponse(Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
    }

    #[Test]
    #[TestDox('accepts a file sitting exactly on the size limit')]
    public function itAcceptsAFileAtTheLimit(): void
    {
        $this->postFile(SampleFile::csvOfSize($this->maxUploadBytes()));

        self::assertResponseStatusCodeSame(
            Response::HTTP_CREATED,
            'The limit is inclusive: a file of exactly the maximum size is valid.',
        );
    }

    #[Test]
    #[TestDox('rejects a file one byte over the limit with 413')]
    public function itRejectsAFileOverTheLimit(): void
    {
        $this->postFile(SampleFile::csvOfSize($this->maxUploadBytes() + 1));

        $problem = $this->assertProblemResponse(Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
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
        // The app limit is pinned to PHP's upload_max_filesize, so the two
        // fire at the same boundary and PHP may well get there first. When it
        // does, the file on disk is empty or partial and unreadable: touching
        // it before checking the error code is how this becomes a 500.
        $this->postFile(SampleFile::csv(), error: \UPLOAD_ERR_INI_SIZE);

        $problem = $this->assertProblemResponse(Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        self::assertStringContainsString((string) $this->maxUploadBytes(), $problem['detail']);
    }

    #[Test]
    #[TestDox('reports a truncated upload as 422, not a crash')]
    public function itMapsAPartialUploadToAClientError(): void
    {
        // The connection dropped mid-upload. Nothing is wrong on our side.
        $this->postFile(SampleFile::csv(), error: \UPLOAD_ERR_PARTIAL);

        $this->assertProblemResponse(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    #[Test]
    #[TestDox('reports a body PHP discarded as 413, not as a missing file')]
    public function itRecognisesAnUploadDroppedByPhp(): void
    {
        // Past post_max_size PHP empties $_FILES, so the naive reading is
        // "no file was sent" -> 422. Content-Length says otherwise, and 413
        // is both true and actionable; 422 would send the caller hunting for
        // a bug in their multipart encoding.
        $this->postFileDroppedByPhp($this->maxUploadBytes() * 8);

        $this->assertProblemResponse(Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
    }

    #[Test]
    #[TestDox('rejects an empty file with 422')]
    public function itRejectsAnEmptyFile(): void
    {
        // Zero bytes is well-formed as a request and useless as a job.
        $this->postFile(SampleFile::empty());

        $this->assertProblemResponse(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    #[Test]
    #[TestDox('rejects a request with no file part with 422')]
    public function itRejectsARequestWithoutAFile(): void
    {
        $this->postFileWithoutAttachment();

        $this->assertProblemResponse(Response::HTTP_UNPROCESSABLE_ENTITY);
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
