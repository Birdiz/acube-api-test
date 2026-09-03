<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Api\Support\ApiAssert;
use App\Tests\Api\Support\ApiClient;
use App\Tests\Api\Support\ConversionQueue;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Wiring for the black-box API tests.
 *
 * This class only composes: it boots the kernel, hands the tests an
 * {@see ApiClient} to make requests with and a {@see ConversionQueue} to run
 * the worker, and enforces the one invariant that holds for every test.
 * Assertions live in {@see ApiAssert}.
 *
 * The tests themselves only ever talk HTTP — they know routes, status codes and
 * payload shapes, never the classes behind them, so they are written before the
 * implementation exists and survive any reasonable way of building it.
 */
abstract class ApiTestCase extends WebTestCase
{
    protected ApiClient $api;

    protected ConversionQueue $queue;

    protected function setUp(): void
    {
        // Each test gets a fresh kernel; disableReboot() below means the
        // previous one is still up when we get here.
        self::ensureKernelShutdown();

        $browser = static::createClient();

        // The in-memory transport lives in the container: rebooting the kernel
        // between requests would throw the queued jobs away.
        $browser->disableReboot();

        // Every response is checked for a server error as it arrives.
        $this->api = new ApiClient($browser, ApiAssert::noServerError(...));
        $this->queue = new ConversionQueue(static::getContainer());

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

    /**
     * The configured upload limit, read rather than duplicated: a test that
     * hardcodes it would keep passing after the limit moved.
     */
    protected function maxUploadBytes(): int
    {
        $configured = $_ENV['FILE_MAX_SIZE_BYTES'] ?? null;

        self::assertNotNull($configured, 'FILE_MAX_SIZE_BYTES must be configured.');

        return (int) $configured;
    }

    // ------------------------------------------------------------- shortcuts

    // These two compose a request and its expected outcome. They are used where
    // a test needs a file or a conversion to *exist* before it can get to its
    // own subject; the endpoints themselves are covered on their own elsewhere.

    /** Uploads a file, asserts it was accepted, and returns the new file id. */
    protected function uploadFile(string $path): string
    {
        $response = $this->api->postFile($path);

        self::assertResponseStatusCodeSame(
            Response::HTTP_CREATED,
            \sprintf('Expected %s to be accepted as a source file.', basename($path)),
        );

        return ApiAssert::json($response)['id'];
    }

    /** Requests a conversion, asserts it was accepted, and returns its id. */
    protected function requestConversion(string $fileId, string $format): string
    {
        $response = $this->api->postConversion($fileId, ['format' => $format]);

        self::assertResponseStatusCodeSame(
            Response::HTTP_ACCEPTED,
            \sprintf('Expected a conversion to "%s" to be accepted.', $format),
        );

        return ApiAssert::json($response)['id'];
    }

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
