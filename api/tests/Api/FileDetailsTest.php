<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Api\Fixture\SampleFile;
use App\Tests\Api\Support\ApiAssert;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /api/files/{id} — the address the upload's `Location` names.
 *
 * A `201` promises the created resource is *at* that URL, so the header is
 * only worth as much as what answers there. Asserting the string alone would
 * pass over a header pointing at nothing.
 */
#[TestDox('GET /api/files/{id}')]
final class FileDetailsTest extends ApiTestCase
{
    #[Test]
    #[TestDox('answers at the Location the upload handed out')]
    public function itAnswersAtTheLocationTheUploadHandedOut(): void
    {
        $response = $this->api->postFile(SampleFile::csv());
        $fileId = ApiAssert::stringField(ApiAssert::json($response), 'id');

        $location = ApiAssert::locationMatches($response, '/api/files/{id}', $fileId);

        $this->api->get($location);

        self::assertResponseStatusCodeSame(
            Response::HTTP_OK,
            'The Location of a 201 must resolve; a header pointing at nothing is worse than none.',
        );
    }

    /**
     * The one thing a caller cannot work out for themselves: the type was read
     * from the bytes, so what we accepted may not be what they thought they
     * sent.
     */
    #[Test]
    #[TestDox('reports the type that was detected, not the one that was claimed')]
    public function itReportsTheDetectedType(): void
    {
        $response = $this->api->postFile(SampleFile::json(), sentName: 'data.csv', sentMimeType: 'text/csv');
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $fileId = ApiAssert::stringField(ApiAssert::json($response), 'id');

        $body = ApiAssert::json($this->api->getFile($fileId));
        self::assertResponseIsSuccessful();

        self::assertSame($fileId, $body['id'] ?? null);
        self::assertSame('json', $body['format'] ?? null, 'The bytes were JSON, whatever the upload called them.');
        self::assertSame('data.csv', $body['filename'] ?? null, 'The name the caller sent is echoed back, never used.');
        self::assertGreaterThan(0, $body['size_bytes'] ?? 0);
        self::assertIsString($body['created_at'] ?? null);
    }

    #[Test]
    #[TestDox('answers 404 for an id that was never handed out')]
    public function itAnswers404ForAnUnknownFile(): void
    {
        $response = $this->api->getFile('01ARZ3NDEKTSV4RRFFQ69G5FAV');

        ApiAssert::problem($response, Response::HTTP_NOT_FOUND);
    }
}
