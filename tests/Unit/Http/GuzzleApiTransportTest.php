<?php

declare(strict_types=1);

namespace MaxRzDev\DummyJsonUserClient\Tests\Unit\Http;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use JsonException;
use MaxRzDev\DummyJsonUserClient\Exception\RateLimitException;
use MaxRzDev\DummyJsonUserClient\Exception\RemoteServerException;
use MaxRzDev\DummyJsonUserClient\Exception\TransportException;
use MaxRzDev\DummyJsonUserClient\Exception\UnexpectedResponseException;
use MaxRzDev\DummyJsonUserClient\Exception\UserNotFoundException;
use MaxRzDev\DummyJsonUserClient\Http\GuzzleApiTransport;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class GuzzleApiTransportTest extends TestCase
{
    /**
     * @throws JsonException
     */
    public function testItDecodesSuccessfulJsonResponses(): void
    {
        $history = [];
        $transport = $this->transport([
            new Response(200, [], json_encode(['id' => 1], JSON_THROW_ON_ERROR)),
        ], $history);

        self::assertSame(['id' => 1], $transport->get('/users/1', ['select' => 'id']));
        self::assertCount(1, $history);
        self::assertSame('GET', $history[0]['request']->getMethod());
        self::assertSame('https://example.test/users/1?select=id', (string) $history[0]['request']->getUri());
    }

    public function testItThrowsForInvalidJsonResponses(): void
    {
        $transport = $this->transport([
            new Response(200, [], '{bad json'),
        ]);

        $this->expectException(UnexpectedResponseException::class);

        $transport->get('/users/1');
    }

    public function testItThrowsForNonObjectJsonResponses(): void
    {
        $transport = $this->transport([
            new Response(200, [], 'null'),
        ]);

        $this->expectException(UnexpectedResponseException::class);

        $transport->get('/users/1');
    }

    public function testItTranslatesNotFoundResponses(): void
    {
        $transport = $this->transport([
            new Response(404, [], '{"message":"User not found"}'),
        ]);

        try {
            $transport->get('/users/999');
            self::fail('Expected a user not found exception.');
        } catch (UserNotFoundException $exception) {
            self::assertSame(404, $exception->statusCode());
            self::assertSame('{"message":"User not found"}', $exception->responseBody());
            self::assertSame('User not found', $exception->apiMessage());
            self::assertStringContainsString('User not found', $exception->getMessage());
        }
    }

    public function testItTranslatesRateLimitResponses(): void
    {
        $transport = $this->transport([
            new Response(429, [], '{}'),
        ], maxRetries: 0);

        $this->expectException(RateLimitException::class);

        $transport->get('/users/1');
    }

    public function testItTranslatesServerErrors(): void
    {
        $transport = $this->transport([
            new Response(500, [], '{}'),
        ], maxRetries: 0);

        $this->expectException(RemoteServerException::class);

        $transport->get('/users/1');
    }

    public function testItTranslatesTransportFailures(): void
    {
        $transport = $this->transport([
            new ConnectException('Could not connect.', new Request('GET', 'https://example.test/users/1')),
        ], maxRetries: 0);

        $this->expectException(TransportException::class);

        $transport->get('/users/1');
    }

    /**
     * @throws JsonException
     */
    public function testItRetriesTemporaryGetFailures(): void
    {
        $history = [];
        $transport = $this->transport([
            new Response(500, [], '{}'),
            new Response(200, [], json_encode(['ok' => true], JSON_THROW_ON_ERROR)),
        ], $history, maxRetries: 1);

        self::assertSame(['ok' => true], $transport->get('/users/1'));
        self::assertCount(2, $history);
    }

    /**
     * @throws JsonException
     */
    public function testItRetriesNetworkFailuresForGets(): void
    {
        $history = [];
        $delays = [];
        $transport = $this->transport([
            new ConnectException('Could not connect.', new Request('GET', 'https://example.test/users/1')),
            new Response(200, [], json_encode(['ok' => true], JSON_THROW_ON_ERROR)),
        ], $history, maxRetries: 1, baseRetryDelayMilliseconds: 100, sleep: static function (int $delayMilliseconds) use (&$delays): void {
            $delays[] = $delayMilliseconds;
        });

        self::assertSame(['ok' => true], $transport->get('/users/1'));
        self::assertCount(2, $history);
        self::assertSame([100], $delays);
    }

    /**
     * @throws JsonException
     */
    public function testItAppliesExponentialBackoffBetweenTemporaryGetFailures(): void
    {
        $delays = [];
        $transport = $this->transport([
            new Response(500, [], '{}'),
            new Response(503, [], '{}'),
            new Response(200, [], json_encode(['ok' => true], JSON_THROW_ON_ERROR)),
        ], maxRetries: 2, baseRetryDelayMilliseconds: 125, sleep: static function (int $delayMilliseconds) use (&$delays): void {
            $delays[] = $delayMilliseconds;
        });

        self::assertSame(['ok' => true], $transport->get('/users/1'));
        self::assertSame([125, 250], $delays);
    }

    /**
     * @throws JsonException
     */
    public function testItUsesRetryAfterHeaderBeforeBackoff(): void
    {
        $delays = [];
        $transport = $this->transport([
            new Response(429, ['Retry-After' => '2'], '{}'),
            new Response(200, [], json_encode(['ok' => true], JSON_THROW_ON_ERROR)),
        ], maxRetries: 1, baseRetryDelayMilliseconds: 100, sleep: static function (int $delayMilliseconds) use (&$delays): void {
            $delays[] = $delayMilliseconds;
        });

        self::assertSame(['ok' => true], $transport->get('/users/1'));
        self::assertSame([2_000], $delays);
    }

    public function testItStopsAfterTheRetryLimit(): void
    {
        $history = [];
        $transport = $this->transport([
            new Response(500, [], '{}'),
            new Response(500, [], '{}'),
        ], $history, maxRetries: 1);

        try {
            $transport->get('/users/1');
            self::fail('Expected a remote server exception.');
        } catch (RemoteServerException) {
            self::assertCount(2, $history);
        }
    }

    public function testItDoesNotRetryPosts(): void
    {
        $history = [];
        $transport = $this->transport([
            new Response(500, [], '{}'),
        ], $history, maxRetries: 2);

        try {
            $transport->post('/users/add', ['firstName' => 'John']);
            self::fail('Expected a remote server exception.');
        } catch (RemoteServerException) {
            self::assertCount(1, $history);
            self::assertSame('POST', $history[0]['request']->getMethod());
        }
    }

    /**
     * @param list<ResponseInterface|\Throwable> $queue
     * @param list<array{request: RequestInterface, response?: ResponseInterface, error?: mixed, options: array<string, mixed>}> $history
     */
    private function transport(
        array $queue,
        array &$history = [],
        int $maxRetries = 2,
        int $baseRetryDelayMilliseconds = 0,
        ?\Closure $sleep = null,
    ): GuzzleApiTransport {
        $mock = new MockHandler($queue);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));

        return new GuzzleApiTransport(
            client: new Client(['handler' => $stack]),
            baseUrl: 'https://example.test',
            maxRetries: $maxRetries,
            baseRetryDelayMilliseconds: $baseRetryDelayMilliseconds,
            sleep: $sleep,
        );
    }
}
