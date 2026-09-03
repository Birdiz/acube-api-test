<?php

declare(strict_types=1);

namespace App\Tests\Api\Support;

use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\Response;

/**
 * Assertions about what the conversion API sent back.
 *
 * These are the rules that hold across endpoints — how an error is shaped, what
 * an id looks like, where a Location points — kept apart from the client that
 * makes the requests so each can be read on its own.
 */
final class ApiAssert extends Assert
{
    /**
     * Every error is expected to be an RFC 9457 problem document: a bare status
     * code is not an explicit error.
     *
     * @return array<string, mixed> the problem document, for further assertions
     */
    public static function problem(Response $response, int $expectedStatus): array
    {
        self::assertSame(
            $expectedStatus,
            $response->getStatusCode(),
            \sprintf('Expected HTTP %d.', $expectedStatus),
        );
        self::assertStringContainsString(
            'application/problem+json',
            (string) $response->headers->get('Content-Type'),
            'Errors are expected to be RFC 9457 problem documents.',
        );

        $problem = self::json($response);

        self::assertSame($expectedStatus, $problem['status'] ?? null, 'The problem document must echo the status.');
        self::assertNotEmpty($problem['title'] ?? null, 'The problem document must carry a title.');
        self::assertNotEmpty($problem['detail'] ?? null, 'The problem document must explain what went wrong.');

        return $problem;
    }

    /** @return array<string, mixed> */
    public static function json(Response $response): array
    {
        $content = $response->getContent();
        self::assertIsString($content);

        $decoded = json_decode($content, true);
        self::assertIsArray($decoded, \sprintf('Expected a JSON object, got: %s', substr($content, 0, 200)));

        return $decoded;
    }

    /** @return non-empty-string the resolved URL, for following */
    public static function locationMatches(Response $response, string $template, string $id): string
    {
        $expected = str_replace('{id}', $id, $template);

        self::assertSame(
            $expected,
            $response->headers->get('Location'),
            'The Location header must point at the resource that was just created.',
        );

        return $expected;
    }

    public static function opaqueId(string $id): void
    {
        self::assertNotSame('', $id);
        self::assertMatchesRegularExpression(
            '/^[0-9a-zA-Z][0-9a-zA-Z_-]{7,}$/',
            $id,
            'Ids are expected to be opaque and URL-safe (a UUID or ULID), never a guessable counter.',
        );
    }

    /**
     * Nothing a caller can send may produce a 5xx: anything wrong with a
     * request is a 4xx problem document. A 5xx is reserved for faults that are
     * genuinely ours, where changing the request would not help.
     *
     * Checked as each response arrives rather than at the end of the test, so
     * a crash is reported where it happened and is not masked by whatever
     * assertion would have failed next.
     */
    public static function noServerError(Response $response): void
    {
        self::assertLessThan(500, $response->getStatusCode(), \sprintf(
            'The API answered %d. A caller must never be able to trigger a server error; '
            .'anything wrong with a request is a 4xx problem document.',
            $response->getStatusCode(),
        ));
    }
}
