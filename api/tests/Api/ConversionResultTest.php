<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Api\Fixture\SampleFile;
use App\Tests\Api\Support\ApiAssert;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /api/conversions/{id}/result — the payoff, once there is one.
 *
 * The interesting case is asking too early. 404 would be wrong: the conversion
 * exists, the caller has its id from a 202, and telling them it does not exist
 * invites them to retry the whole flow. 409 Conflict says the resource is real
 * but its current state does not allow this — which is exactly the situation,
 * and it is recoverable by waiting.
 */
#[TestDox('GET /api/conversions/{id}/result')]
final class ConversionResultTest extends ApiTestCase
{

    #[Test]
    #[TestDox('refuses a pending conversion with 409, not 404')]
    public function itRefusesAPendingResultWithAConflict(): void
    {
        $fileId = $this->uploadFile(SampleFile::csv());
        $conversionId = $this->requestConversion($fileId, 'xml');

        $this->api->getConversionResult($conversionId);

        $problem = ApiAssert::problem($this->api->response(), Response::HTTP_CONFLICT);

        // "Not ready" and "does not exist" are different problems and the
        // caller reacts differently to each: wait, versus start over.
        self::assertSame(
            'pending',
            $problem['conversion_status'] ?? null,
            'The error should carry the state that blocked the request.',
        );
    }

    #[Test]
    #[TestDox('points a pending caller back at the status resource')]
    public function itTellsAPendingCallerWhereToLook(): void
    {
        $fileId = $this->uploadFile(SampleFile::csv());
        $conversionId = $this->requestConversion($fileId, 'json');

        $this->api->getConversionResult($conversionId);

        $problem = ApiAssert::problem($this->api->response(), Response::HTTP_CONFLICT);
        self::assertSame(
            \sprintf('/api/conversions/%s', $conversionId),
            $problem['status_url'] ?? null,
            'An explicit error says what to do next, not just what went wrong.',
        );
    }

    #[Test]
    #[TestDox('serves a JSON conversion as application/json')]
    public function itServesAJsonResult(): void
    {
        $fileId = $this->uploadFile(SampleFile::csv());
        $conversionId = $this->requestConversion($fileId, 'json');

        $this->queue->drain();
        $this->api->getConversionResult($conversionId);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertStringStartsWith(
            'application/json',
            (string) $this->api->response()->headers->get('Content-Type'),
            'The result is served as what it is, not as a generic blob.',
        );

        $content = (string) $this->api->response()->getContent();
        self::assertNotSame('', $content, 'A done conversion must have produced something.');
        self::assertJson($content);
    }

    #[Test]
    #[TestDox('serves an XML conversion as application/xml')]
    public function itServesAnXmlResult(): void
    {
        $fileId = $this->uploadFile(SampleFile::csv());
        $conversionId = $this->requestConversion($fileId, 'xml');

        $this->queue->drain();
        $this->api->getConversionResult($conversionId);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertStringStartsWith(
            'application/xml',
            (string) $this->api->response()->headers->get('Content-Type'),
        );

        $content = (string) $this->api->response()->getContent();
        self::assertNotSame('', $content);

        $previous = libxml_use_internal_errors(true);
        $document = simplexml_load_string($content);
        libxml_use_internal_errors($previous);

        self::assertNotFalse($document, 'An XML conversion must return well-formed XML.');
    }

    #[Test]
    #[TestDox('offers the result as a downloadable file')]
    public function itOffersTheResultAsAFile(): void
    {
        $fileId = $this->uploadFile(SampleFile::xlsx());
        $conversionId = $this->requestConversion($fileId, 'xml');

        $this->queue->drain();
        $this->api->getConversionResult($conversionId);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $disposition = (string) $this->api->response()->headers->get('Content-Disposition');
        self::assertStringContainsString('attachment', $disposition);
        self::assertMatchesRegularExpression(
            '/filename[^;=]*=(["\']?)[^"\';]+\.xml\1/',
            $disposition,
            'The customer asked for a file; give it a name and the right extension.',
        );
    }

    #[Test]
    #[TestDox('can be fetched more than once')]
    public function itIsRepeatable(): void
    {
        $fileId = $this->uploadFile(SampleFile::json());
        $conversionId = $this->requestConversion($fileId, 'xml');

        $this->queue->drain();

        $this->api->getConversionResult($conversionId);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $first = $this->api->response()->getContent();

        $this->api->getConversionResult($conversionId);
        self::assertResponseStatusCodeSame(Response::HTTP_OK, 'Fetching a result must not consume it.');
        self::assertSame($first, $this->api->response()->getContent());
    }

    #[Test]
    #[TestDox('rejects an unknown conversion id with 404')]
    public function itRejectsAnUnknownConversion(): void
    {
        $this->api->getConversionResult('01JQZ0000000000000000MISSING');

        ApiAssert::problem($this->api->response(), Response::HTTP_NOT_FOUND);
    }
}
