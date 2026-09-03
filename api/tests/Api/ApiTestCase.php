<?php

declare(strict_types=1);

namespace App\Tests\Api;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Black-box base class for the conversion API.
 *
 * The tests below only ever talk HTTP: they know the routes, the status codes
 * and the payload shapes, never the classes behind them. That is deliberate —
 * they are written before the implementation exists and must survive any
 * reasonable way of building it.
 *
 * The single exception is {@see runConversionWorker()}, which drains the
 * Messenger queue in-process. A functional test has no background worker, so
 * something has to stand in for it; keeping that in one method means an
 * implementation that queues work differently only has to touch this file.
 */
abstract class ApiTestCase extends WebTestCase
{
    /** Kept in sync with FILE_MAX_SIZE_BYTES in .env.test. */
    protected const int MAX_UPLOAD_BYTES = 1048576;

    protected const string TRANSPORT = 'conversions';

    protected KernelBrowser $client;

    protected function setUp(): void
    {
        // Each test gets a fresh kernel; disableReboot() below means the
        // previous one is still up when we get here.
        self::ensureKernelShutdown();

        $this->client = static::createClient();

        // The in-memory transport lives in the container: rebooting the kernel
        // between requests would throw the queued jobs away.
        $this->client->disableReboot();

        $this->resetDatabase();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // Fixtures are deliberately *not* removed here: data providers resolve
        // their paths when the suite is loaded, so deleting files mid-run would
        // pull the ground out from under tests that have not started yet.
        // tests/bootstrap.php clears the directory once, before anything runs.
    }

    // ---------------------------------------------------------------- requests

    /**
     * POST /api/files with a multipart body.
     *
     * $sentName and $sentMimeType let a test lie about what it is uploading,
     * which is how "trust the bytes, not the client" gets verified.
     */
    protected function postFile(string $path, ?string $sentName = null, ?string $sentMimeType = null): void
    {
        $upload = new UploadedFile(
            path: $path,
            originalName: $sentName ?? basename($path),
            mimeType: $sentMimeType,
            test: true,
        );

        $this->client->request('POST', '/api/files', files: ['file' => $upload]);
    }

    /** POST /api/files with no `file` part at all. */
    protected function postFileWithoutAttachment(): void
    {
        $this->client->request('POST', '/api/files');
    }

    protected function postConversion(string $fileId, mixed $body): void
    {
        $this->client->request(
            'POST',
            \sprintf('/api/files/%s/conversions', $fileId),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: \is_string($body) ? $body : json_encode($body, \JSON_THROW_ON_ERROR),
        );
    }

    protected function getConversion(string $conversionId): void
    {
        $this->client->request('GET', \sprintf('/api/conversions/%s', $conversionId));
    }

    protected function getConversionResult(string $conversionId): void
    {
        $this->client->request('GET', \sprintf('/api/conversions/%s/result', $conversionId));
    }

    // ----------------------------------------------------------- happy shortcuts

    /** Uploads a file, asserts it was accepted, and returns the new file id. */
    protected function uploadFile(string $path): string
    {
        $this->postFile($path);
        self::assertResponseStatusCodeSame(
            Response::HTTP_CREATED,
            \sprintf('Expected %s to be accepted as a source file.', basename($path)),
        );

        return $this->responseBody()['id'];
    }

    /** Requests a conversion, asserts it was accepted, and returns its id. */
    protected function requestConversion(string $fileId, string $format): string
    {
        $this->postConversion($fileId, ['format' => $format]);
        self::assertResponseStatusCodeSame(
            Response::HTTP_ACCEPTED,
            \sprintf('Expected a conversion to "%s" to be accepted.', $format),
        );

        return $this->responseBody()['id'];
    }

    // ----------------------------------------------------------------- worker

    /**
     * Runs every queued conversion job to completion, standing in for the
     * `messenger:consume conversions` worker that runs in production.
     */
    protected function runConversionWorker(): void
    {
        $container = static::getContainer();

        $transportId = 'messenger.transport.'.self::TRANSPORT;
        self::assertTrue(
            $container->has($transportId),
            \sprintf('Conversion jobs are expected to be queued on the "%s" transport.', self::TRANSPORT),
        );

        $transport = $container->get($transportId);
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        $bus = $container->get(MessageBusInterface::class);

        // A job may legitimately queue follow-up work, so keep going until the
        // queue is empty rather than draining a single batch.
        while ([] !== $envelopes = $transport->get()) {
            foreach ($envelopes as $envelope) {
                // ReceivedStamp tells the bus to handle the message here
                // instead of putting it back on the transport.
                $bus->dispatch($envelope->with(new ReceivedStamp(self::TRANSPORT)));
                $transport->ack($envelope);
            }
        }
    }

    // ------------------------------------------------------------- assertions

    protected function response(): Response
    {
        return $this->client->getResponse();
    }

    /** @return array<string, mixed> */
    protected function responseBody(): array
    {
        $content = $this->response()->getContent();
        self::assertIsString($content);

        $decoded = json_decode($content, true);
        self::assertIsArray($decoded, \sprintf('Expected a JSON object, got: %s', substr((string) $content, 0, 200)));

        return $decoded;
    }

    /**
     * Every error is expected to be an RFC 9457 problem document — a bare
     * status code is not an explicit error.
     *
     * @return array<string, mixed>
     */
    protected function assertProblemResponse(int $expectedStatus): array
    {
        self::assertResponseStatusCodeSame($expectedStatus);
        self::assertStringContainsString(
            'application/problem+json',
            (string) $this->response()->headers->get('Content-Type'),
            'Errors are expected to be RFC 9457 problem documents.',
        );

        $problem = $this->responseBody();
        self::assertSame($expectedStatus, $problem['status'] ?? null, 'The problem document must echo the status.');
        self::assertNotEmpty($problem['title'] ?? null, 'The problem document must carry a title.');
        self::assertNotEmpty($problem['detail'] ?? null, 'The problem document must explain what went wrong.');

        return $problem;
    }

    /** @return non-empty-string */
    protected function assertLocationMatches(string $template, string $id): string
    {
        $expected = str_replace('{id}', $id, $template);
        self::assertSame(
            $expected,
            $this->response()->headers->get('Location'),
            'The Location header must point at the resource that was just created.',
        );

        return $expected;
    }

    protected function assertIdIsOpaqueAndStable(string $id): void
    {
        self::assertNotSame('', $id);
        self::assertMatchesRegularExpression(
            '/^[0-9a-zA-Z][0-9a-zA-Z_-]{7,}$/',
            $id,
            'Ids are expected to be opaque and URL-safe (a UUID or ULID), never a guessable counter.',
        );
    }

    // ------------------------------------------------------------------ setup

    private function resetDatabase(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);

        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();

        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }
}
