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
 * Composition only: requests go through {@see ApiClient}, assertions live in
 * {@see ApiAssert}, the worker is {@see ConversionQueue}.
 */
abstract class ApiTestCase extends WebTestCase
{
    protected ApiClient $api;

    protected ConversionQueue $queue;

    protected function setUp(): void
    {
        // disableReboot() below leaves the previous kernel up.
        self::ensureKernelShutdown();

        $browser = static::createClient();

        // The queued jobs live in the container, so a reboot would drop them.
        $browser->disableReboot();

        $this->api = new ApiClient($browser, ApiAssert::noServerError(...));
        $this->queue = new ConversionQueue(static::getContainer());

        $this->resetDatabase();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // Fixtures are *not* cleaned up here. Data providers resolve their
        // paths while the suite loads, so deleting files mid-run would break
        // tests that have not started. bootstrap.php clears them once.
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

    // The two below give a test the file or conversion it needs to *exist*
    // before reaching its own subject. Both endpoints are covered on their own.

    protected function uploadFile(string $path): string
    {
        $response = $this->api->postFile($path);

        self::assertResponseStatusCodeSame(
            Response::HTTP_CREATED,
            \sprintf('Expected %s to be accepted as a source file.', basename($path)),
        );

        return ApiAssert::json($response)['id'];
    }

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
