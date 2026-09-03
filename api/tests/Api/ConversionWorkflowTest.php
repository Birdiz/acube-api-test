<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Api\Fixture\SampleFile;
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
        $this->postFile(SampleFile::xlsx());
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $fileId = $this->responseBody()['id'];
        $this->assertLocationMatches('/api/files/{id}', $fileId);

        // 2. They ask for a conversion. The job runs for minutes, so the answer
        //    is a receipt, not a result.
        $this->postConversion($fileId, ['format' => 'xml']);
        self::assertResponseStatusCodeSame(Response::HTTP_ACCEPTED);
        $conversion = $this->responseBody();
        $conversionId = $conversion['id'];
        self::assertSame('pending', $conversion['status']);
        $statusUrl = $this->assertLocationMatches('/api/conversions/{id}', $conversionId);

        // 3. They poll the address they were given. Still pending: asking for
        //    the result now is a conflict, not a 404.
        $this->client->request('GET', $statusUrl);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame('pending', $this->responseBody()['status']);

        $this->getConversionResult($conversionId);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);

        // 4. The worker gets to it.
        $this->runConversionWorker();

        $this->client->request('GET', $statusUrl);
        self::assertSame('done', $this->responseBody()['status']);

        // 5. And the file is there, as XML.
        $this->getConversionResult($conversionId);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertStringStartsWith('application/xml', (string) $this->response()->headers->get('Content-Type'));
        self::assertNotSame('', (string) $this->response()->getContent());
    }

    #[Test]
    #[TestDox('never blocks the customer, whatever the job costs')]
    public function itNeverBlocksTheCustomer(): void
    {
        $startedAt = microtime(true);

        $fileId = $this->uploadFile(SampleFile::csv());
        $this->requestConversion($fileId, 'json');
        $this->getConversionResult($fileId);

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

        $this->runConversionWorker();

        $this->getConversionResult($asJson);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertStringStartsWith('application/json', (string) $this->response()->headers->get('Content-Type'));

        $this->getConversionResult($asXml);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertStringStartsWith('application/xml', (string) $this->response()->headers->get('Content-Type'));
    }

    #[Test]
    #[TestDox('keeps one customer\'s failure away from another job')]
    public function itIsolatesJobsFromEachOther(): void
    {
        $goodFile = $this->uploadFile(SampleFile::csv());
        $goodConversion = $this->requestConversion($goodFile, 'xml');

        // A second request that is refused up front must leave the first alone.
        $this->postConversion($goodFile, ['format' => 'pdf']);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $this->runConversionWorker();

        $this->getConversion($goodConversion);
        self::assertSame('done', $this->responseBody()['status']);
    }

    #[Test]
    #[TestDox('rejects bad input before any work is queued')]
    public function itRejectsBadInputBeforeQueueingAnything(): void
    {
        // Neither of these should ever reach the worker: both are knowable now.
        $this->postFile(SampleFile::pdf());
        self::assertResponseStatusCodeSame(Response::HTTP_UNSUPPORTED_MEDIA_TYPE);

        $fileId = $this->uploadFile(SampleFile::csv());
        $this->postConversion($fileId, ['format' => 'pdf']);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $this->runConversionWorker();

        // Nothing was queued, so nothing ran, so nothing failed.
        $this->getConversion($fileId);
        self::assertResponseStatusCodeSame(
            Response::HTTP_NOT_FOUND,
            'A file id is not a conversion id; the two namespaces stay separate.',
        );
    }
}
