<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Api\Fixture\SampleFile;
use App\Tests\Api\Support\ApiAssert;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /api/files/{fileId}/conversions — ask for the work, don't wait for it.
 *
 * This is where the 202 decision lives (docs/architecture.md). The job takes
 * more than two minutes, so the response cannot be the result: it is a receipt
 * for work that has been accepted, plus the address to follow it at.
 *
 * The corollary the tests pin down: everything that can be known up front must
 * be decided up front. An unsupported conversion is a 422 on this request, not
 * a `failed` status discovered two minutes later.
 */
#[TestDox('POST /api/files/{fileId}/conversions')]
final class ConversionRequestTest extends ApiTestCase
{

    /** @return iterable<string, array{string, string}> */
    public static function supportedCouples(): iterable
    {
        foreach (['CSV' => 'csv', 'JSON' => 'json', 'XLSX' => 'xlsx', 'ODS' => 'ods'] as $label => $source) {
            foreach (['json', 'xml'] as $target) {
                yield \sprintf('%s to %s', $label, strtoupper($target)) => [$source, $target];
            }
        }
    }

    #[Test]
    #[DataProvider('supportedCouples')]
    #[TestDox('accepts $_dataName with 202, a pending status and a Location')]
    public function itAcceptsEverySupportedCouple(string $source, string $target): void
    {
        $fileId = $this->uploadFile(SampleFile::$source());

        $this->api->postConversion($fileId, ['format' => $target]);

        self::assertResponseStatusCodeSame(
            Response::HTTP_ACCEPTED,
            '202 Accepted: the work is queued, not done.',
        );

        $body = $this->body();
        self::assertArrayHasKey('id', $body);
        self::assertIsString($body['id']);
        ApiAssert::opaqueId($body['id']);

        self::assertSame(
            'pending',
            $body['status'] ?? null,
            'A conversion starts pending; nothing has run yet when this response is written.',
        );

        // The conversion is its own resource: it outlives the file it came from
        // and is polled at the top level, not under /api/files.
        ApiAssert::locationMatches($this->api->response(), '/api/conversions/{id}', $body['id']);
    }

    #[Test]
    #[TestDox('answers immediately instead of waiting for the conversion')]
    public function itDoesNotBlockOnTheConversion(): void
    {
        $fileId = $this->uploadFile(SampleFile::csv());

        $startedAt = microtime(true);
        $this->api->postConversion($fileId, ['format' => 'xml']);
        $elapsed = microtime(true) - $startedAt;

        self::assertResponseStatusCodeSame(Response::HTTP_ACCEPTED);
        self::assertLessThan(
            5.0,
            $elapsed,
            'The whole point of 202 is that the request returns long before the job does.',
        );
    }

    #[Test]
    #[TestDox('rejects an unknown fileId with 404')]
    public function itRejectsAnUnknownFile(): void
    {
        $this->api->postConversion('01JQZ0000000000000000UNKN0WN', ['format' => 'json']);

        ApiAssert::problem($this->api->response(), Response::HTTP_NOT_FOUND);
    }

    #[Test]
    #[TestDox('rejects an unknown fileId even when the format is also invalid')]
    public function itReportsTheMissingFileBeforeValidatingTheFormat(): void
    {
        // The path identifies the resource being acted on; if it isn't there,
        // there is nothing to validate a body against.
        $this->api->postConversion('01JQZ0000000000000000UNKN0WN', ['format' => 'pdf']);

        ApiAssert::problem($this->api->response(), Response::HTTP_NOT_FOUND);
    }

    #[Test]
    #[TestDox('rejects an unsupported output format with 422, up front')]
    public function itRejectsAnUnsupportedOutputFormat(): void
    {
        $fileId = $this->uploadFile(SampleFile::csv());

        $this->api->postConversion($fileId, ['format' => 'pdf']);

        $problem = ApiAssert::problem($this->api->response(), Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsStringIgnoringCase('pdf', $problem['detail']);
    }

    #[Test]
    #[TestDox('creates nothing when the couple is unsupported')]
    public function itDoesNotCreateAConversionForAnUnsupportedCouple(): void
    {
        $fileId = $this->uploadFile(SampleFile::csv());

        $this->api->postConversion($fileId, ['format' => 'pdf']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertFalse(
            $this->api->response()->headers->has('Location'),
            'A rejected request must not leave a conversion behind to poll.',
        );
        self::assertArrayNotHasKey('id', $this->body());
    }

    #[Test]
    #[TestDox('lists the formats it does support when it refuses one')]
    public function itAdvertisesTheSupportedFormatsOnRejection(): void
    {
        $fileId = $this->uploadFile(SampleFile::csv());

        $this->api->postConversion($fileId, ['format' => 'yaml']);

        $problem = ApiAssert::problem($this->api->response(), Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertArrayHasKey(
            'supported_formats',
            $problem,
            'A 422 that does not say what *is* allowed makes the caller guess.',
        );
        self::assertEqualsCanonicalizing(['json', 'xml'], $problem['supported_formats']);
    }

    #[Test]
    #[TestDox('rejects a missing format with 422')]
    public function itRejectsAMissingFormat(): void
    {
        $fileId = $this->uploadFile(SampleFile::csv());

        $this->api->postConversion($fileId, []);

        ApiAssert::problem($this->api->response(), Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    #[Test]
    #[TestDox('rejects a malformed JSON body with 400')]
    public function itRejectsAMalformedBody(): void
    {
        $fileId = $this->uploadFile(SampleFile::csv());

        // Not a validation failure — the request itself is unparseable.
        $this->api->postConversion($fileId, '{"format": ');

        ApiAssert::problem($this->api->response(), Response::HTTP_BAD_REQUEST);
    }

    #[Test]
    #[TestDox('treats the format case-insensitively')]
    public function itNormalisesTheRequestedFormat(): void
    {
        $fileId = $this->uploadFile(SampleFile::csv());

        $conversionId = $this->requestConversion($fileId, 'XML');

        $this->api->getConversion($conversionId);
        self::assertSame('xml', $this->body()['format']);
    }

    #[Test]
    #[TestDox('allows several conversions of the same file')]
    public function itAllowsSeveralConversionsOfOneFile(): void
    {
        $fileId = $this->uploadFile(SampleFile::csv());

        $asJson = $this->requestConversion($fileId, 'json');
        $asXml = $this->requestConversion($fileId, 'xml');

        self::assertNotSame($asJson, $asXml, 'One upload, two independent jobs.');
    }
}
