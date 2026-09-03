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

        $response = $this->api->getConversion($conversionId);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertResponseHeaderSame('Content-Type', 'application/json');

        $body = ApiAssert::json($response);
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

        $response = $this->api->getConversion($conversionId);

        self::assertStringContainsString(
            'no-store',
            (string) $response->headers->get('Cache-Control'),
            'A polled status endpoint that can be cached is a status endpoint that lies.',
        );
    }

    #[Test]
    #[TestDox('gives created_at as a full date, time and offset')]
    public function itTimestampsTheConversion(): void
    {
        $fileId = $this->uploadFile(SampleFile::json());
        $conversionId = $this->requestConversion($fileId, 'xml');

        $response = $this->api->getConversion($conversionId);
        $createdAt = ApiAssert::json($response)['created_at'] ?? null;

        self::assertIsString($createdAt);
        self::assertNotFalse(
            \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $createdAt),
            \sprintf('Expected a date like 2026-09-03T11:22:33+00:00, got "%s".', $createdAt),
        );
    }

    #[Test]
    #[TestDox('reports done once the worker has run')]
    public function itReportsDoneAfterTheWorkerRuns(): void
    {
        $fileId = $this->uploadFile(SampleFile::csv());
        $conversionId = $this->requestConversion($fileId, 'xml');

        $this->queue->drain();

        $response = $this->api->getConversion($conversionId);

        $body = ApiAssert::json($response);
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

        $response = $this->api->getConversion($conversionId);
        $whilePending = ApiAssert::json($response);

        $this->queue->drain();

        $response = $this->api->getConversion($conversionId);
        $whenDone = ApiAssert::json($response);

        self::assertSame($whilePending['id'], $whenDone['id']);
        self::assertSame($whilePending['format'], $whenDone['format']);
        self::assertSame($whilePending['created_at'], $whenDone['created_at']);
        self::assertNotSame($whilePending['status'], $whenDone['status']);
    }

    #[Test]
    #[TestDox('rejects an unknown conversion id with 404')]
    public function itRejectsAnUnknownConversion(): void
    {
        $response = $this->api->getConversion('01JQZ0000000000000000MISSING');

        ApiAssert::problem($response, Response::HTTP_NOT_FOUND);
    }
}
