<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Api\Fixture\SampleFile;
use App\Tests\Api\Support\ApiAssert;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\HttpFoundation\Response;

/**
 * The whole conversation, end to end.
 *
 * The endpoint tests above each pin down one rule. This one reads the way the
 * customer's client actually behaves: upload, ask, poll, collect — and shows
 * that the four steps compose, that the customer is never blocked, and that
 * nothing needs to be known about the file up front.
 */
#[TestDox('The conversion workflow')]
final class ConversionWorkflowTest extends ApiTestCase
{

    #[Test]
    #[TestDox('takes a customer from upload to converted file')]
    public function itTakesACustomerFromUploadToResult(): void
    {
        // 1. The customer hands over a file. Its shape and size are unknown to
        //    us until it lands, so this is the step that validates both.
        $response = $this->api->postFile(SampleFile::xlsx());
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $fileId = ApiAssert::json($response)['id'];
        ApiAssert::locationMatches($response, '/api/files/{id}', $fileId);

        // 2. They ask for a conversion. The job runs for minutes, so the answer
        //    is a receipt, not a result.
        $response = $this->api->postConversion($fileId, ['format' => 'xml']);
        self::assertResponseStatusCodeSame(Response::HTTP_ACCEPTED);
        $conversion = ApiAssert::json($response);
        $conversionId = $conversion['id'];
        self::assertSame('pending', $conversion['status']);
        $statusUrl = ApiAssert::locationMatches($response, '/api/conversions/{id}', $conversionId);

        // 3. They poll the address they were given. Still pending: asking for
        //    the result now is a conflict, not a 404.
        $response = $this->api->get($statusUrl);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame('pending', ApiAssert::json($response)['status']);

        $this->api->getConversionResult($conversionId);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);

        // 4. The worker gets to it.
        $this->queue->drain();

        $response = $this->api->get($statusUrl);
        self::assertSame('done', ApiAssert::json($response)['status']);

        // 5. And the file is there, as XML.
        $response = $this->api->getConversionResult($conversionId);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertStringStartsWith('application/xml', (string) $response->headers->get('Content-Type'));
        self::assertNotSame('', (string) $response->getContent());
    }

    #[Test]
    #[TestDox('never blocks the customer, whatever the job costs')]
    public function itNeverBlocksTheCustomer(): void
    {
        $startedAt = microtime(true);

        $fileId = $this->uploadFile(SampleFile::csv());
        $this->requestConversion($fileId, 'json');
        $this->api->getConversionResult($fileId);

        $elapsed = microtime(true) - $startedAt;

        self::assertLessThan(
            10.0,
            $elapsed,
            'Upload, request and poll are all short requests; only the worker is slow.',
        );
    }

    #[Test]
    #[TestDox('runs independent conversions of one file side by side')]
    public function itRunsSeveralConversionsOfOneFile(): void
    {
        $fileId = $this->uploadFile(SampleFile::csv());

        $asJson = $this->requestConversion($fileId, 'json');
        $asXml = $this->requestConversion($fileId, 'xml');

        $this->queue->drain();

        $response = $this->api->getConversionResult($asJson);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));

        $response = $this->api->getConversionResult($asXml);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertStringStartsWith('application/xml', (string) $response->headers->get('Content-Type'));
    }

    #[Test]
    #[TestDox('keeps one customer\'s failure away from another job')]
    public function itIsolatesJobsFromEachOther(): void
    {
        $goodFile = $this->uploadFile(SampleFile::csv());
        $goodConversion = $this->requestConversion($goodFile, 'xml');

        // A second request that is refused up front must leave the first alone.
        $this->api->postConversion($goodFile, ['format' => 'pdf']);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $this->queue->drain();

        $response = $this->api->getConversion($goodConversion);
        self::assertSame('done', ApiAssert::json($response)['status']);
    }

    #[Test]
    #[TestDox('rejects bad input before any work is queued')]
    public function itRejectsBadInputBeforeQueueingAnything(): void
    {
        // Neither of these should ever reach the worker: both are knowable now.
        $this->api->postFile(SampleFile::pdf());
        self::assertResponseStatusCodeSame(Response::HTTP_UNSUPPORTED_MEDIA_TYPE);

        $fileId = $this->uploadFile(SampleFile::csv());
        $this->api->postConversion($fileId, ['format' => 'pdf']);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $this->queue->drain();

        // Nothing was queued, so nothing ran, so nothing failed.
        $this->api->getConversion($fileId);
        self::assertResponseStatusCodeSame(
            Response::HTTP_NOT_FOUND,
            'A file id is not a conversion id; the two namespaces stay separate.',
        );
    }
}
