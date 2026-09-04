<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Api\Fixture\SampleFile;
use App\Tests\Api\Support\ApiAssert;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /api/docs — the contract as published, not as the suite imagines it.
 *
 * Every other test writes its own URLs, so all of them pass over a document
 * that describes a different API than the one they call. A caller reading the
 * document has no such freedom: Swagger UI fills a path from the parameters
 * declared for it, by name, and sends any variable it cannot name verbatim.
 */
#[TestDox('GET /api/docs')]
final class OpenApiDocumentationTest extends ApiTestCase
{
    /**
     * A parameter no variable is called, or a variable no parameter declares,
     * leaves a hole in the URL that only hand-editing can fill.
     */
    #[Test]
    #[TestDox('names every path variable as a parameter, and declares no others')]
    public function pathParametersMatchTheTemplates(): void
    {
        foreach ($this->documentedOperations() as [$path, $method, $operation]) {
            self::assertSame(
                $this->variablesIn($path),
                $this->pathParameterNamesOf($operation),
                \sprintf(
                    '%s %s must declare a parameter for each of its path variables, under that name.',
                    strtoupper($method),
                    $path,
                ),
            );
        }
    }

    /** Filling the template the documented way has to reach the endpoint. */
    #[Test]
    #[TestDox('documents a conversion request a reader can send as written')]
    public function theDocumentedConversionRequestReaches(): void
    {
        $fileId = $this->uploadFile(SampleFile::csv());

        [$path, , $operation] = $this->documentedOperationFor('post', '/conversions');

        $url = $this->fill($path, $operation, $fileId);

        self::assertStringNotContainsString('{', $url, 'A documented path variable was left unfilled.');

        $this->api->postJson($url, ['format' => 'json']);

        self::assertResponseStatusCodeSame(
            Response::HTTP_ACCEPTED,
            'The URL built from the published document must reach the endpoint the document describes.',
        );
    }

    /**
     * Substitutes by declared parameter name, the way a reader of the document
     * does: a name that matches no variable leaves the template as it was.
     *
     * @param array<string, mixed> $operation
     */
    private function fill(string $path, array $operation, string $value): string
    {
        foreach ($this->pathParameterNamesOf($operation) as $name) {
            $path = str_replace(\sprintf('{%s}', $name), $value, $path);
        }

        return $path;
    }

    /** @return array{string, string, array<string, mixed>} */
    private function documentedOperationFor(string $method, string $pathContains): array
    {
        foreach ($this->documentedOperations() as $documented) {
            [$path, $documentedMethod] = $documented;

            if ($method === $documentedMethod && str_contains($path, $pathContains)) {
                return $documented;
            }
        }

        self::fail(\sprintf('The document describes no %s on a path containing "%s".', strtoupper($method), $pathContains));
    }

    /** @return list<array{string, string, array<string, mixed>}> */
    private function documentedOperations(): array
    {
        $paths = $this->openApi()['paths'] ?? null;

        self::assertIsArray($paths);
        self::assertNotEmpty($paths, 'The published document must describe at least one path.');

        $operations = [];

        foreach ($paths as $path => $methods) {
            self::assertIsArray($methods);

            foreach ($methods as $method => $operation) {
                if (\is_array($operation)) {
                    /** @var array<string, mixed> $operation */
                    $operations[] = [(string) $path, (string) $method, $operation];
                }
            }
        }

        return $operations;
    }

    /**
     * @param array<string, mixed> $operation
     *
     * @return list<string>
     */
    private function pathParameterNamesOf(array $operation): array
    {
        $declared = $operation['parameters'] ?? [];
        self::assertIsArray($declared);

        $names = [];

        foreach ($declared as $parameter) {
            if (\is_array($parameter) && 'path' === ($parameter['in'] ?? null)) {
                $name = $parameter['name'] ?? null;
                self::assertIsString($name, 'A declared parameter must be named.');

                $names[] = $name;
            }
        }

        sort($names);

        return $names;
    }

    /** @return list<string> */
    private function variablesIn(string $path): array
    {
        preg_match_all('/\{(\w+)}/', $path, $matches);

        $names = $matches[1];
        sort($names);

        return $names;
    }

    /** @return array<string, mixed> */
    private function openApi(): array
    {
        $response = $this->api->get('/api/docs.jsonopenapi');

        self::assertResponseIsSuccessful('The OpenAPI document must be published; it is what Swagger UI reads.');

        return ApiAssert::json($response);
    }
}
