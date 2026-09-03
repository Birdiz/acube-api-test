<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Api\Fixture\SampleFile;
use App\Tests\Api\Support\ApiAssert;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /api/conversions/{id} — the resource the 202 pointed at.
 *
 * Returning 202 only works if there is somewhere to look afterwards. This is
 * that place, and it must answer the same way whether the job is queued,
 * running, finished or broken.
 */
#[TestDox('GET /api/conversions/{id}')]
final class ConversionStatusTest extends ApiTestCase
{

    #[Test]
    #[TestDox('describes a freshly queued conversion')]
    public function itDescribesAPendingConversion(): void
    {
        $fileId = $this->uploadFile(SampleFile::csv());
        $conversionId = $this->requestConversion($fileId, 'xml');

        $this->api->getConversion($conversionId);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertResponseHeaderSame('Content-Type', 'application/json');

        $body = $this->body();
        self::assertSame($conversionId, $body['id'] ?? null);
        self::assertSame('pending', $body['status'] ?? null);
        self::assertSame('xml', $body['format'] ?? null);
        self::assertSame($fileId, $body['file_id'] ?? null, 'The conversion should say what it came from.');
        self::assertArrayHasKey('created_at', $body);
    }

    #[Test]
    #[TestDox('is not cached, so polling sees the transition')]
    public function itIsNotCacheable(): void
    {
        $fileId = $this->uploadFile(SampleFile::csv());
        $conversionId = $this->requestConversion($fileId, 'json');

        $this->api->getConversion($conversionId);

        self::assertStringContainsString(
            'no-store',
            (string) $this->api->response()->headers->get('Cache-Control'),
            'A polled status endpoint that can be cached is a status endpoint that lies.',
        );
    }

    #[Test]
    #[TestDox('timestamps the conversion in ISO 8601')]
    public function itTimestampsTheConversion(): void
    {
        $fileId = $this->uploadFile(SampleFile::json());
        $conversionId = $this->requestConversion($fileId, 'xml');

        $this->api->getConversion($conversionId);
        $createdAt = $this->body()['created_at'] ?? null;

        self::assertIsString($createdAt);
        self::assertNotFalse(
            \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $createdAt),
            \sprintf('Expected an ISO 8601 timestamp, got "%s".', $createdAt),
        );
    }

    #[Test]
    #[TestDox('reports done once the worker has run')]
    public function itReportsDoneAfterTheWorkerRuns(): void
    {
        $fileId = $this->uploadFile(SampleFile::csv());
        $conversionId = $this->requestConversion($fileId, 'xml');

        $this->queue->drain();

        $this->api->getConversion($conversionId);

        $body = $this->body();
        self::assertSame('done', $body['status'] ?? null);
        self::assertArrayHasKey('completed_at', $body, 'A finished job should say when it finished.');
        self::assertNotNull($body['completed_at']);
    }

    #[Test]
    #[TestDox('keeps the id and format stable across the lifecycle')]
    public function itKeepsIdentityStableAcrossTheLifecycle(): void
    {
        $fileId = $this->uploadFile(SampleFile::ods());
        $conversionId = $this->requestConversion($fileId, 'json');

        $this->api->getConversion($conversionId);
        $whilePending = $this->body();

        $this->queue->drain();

        $this->api->getConversion($conversionId);
        $whenDone = $this->body();

        self::assertSame($whilePending['id'], $whenDone['id']);
        self::assertSame($whilePending['format'], $whenDone['format']);
        self::assertSame($whilePending['created_at'], $whenDone['created_at']);
        self::assertNotSame($whilePending['status'], $whenDone['status']);
    }

    #[Test]
    #[TestDox('rejects an unknown conversion id with 404')]
    public function itRejectsAnUnknownConversion(): void
    {
        // Nothing was ever created under this id — 404 is the honest answer.
        $this->api->getConversion('01JQZ0000000000000000MISSING');

        ApiAssert::problem($this->api->response(), Response::HTTP_NOT_FOUND);
    }
}
