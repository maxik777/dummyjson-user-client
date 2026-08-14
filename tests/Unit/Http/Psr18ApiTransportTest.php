<?php

declare(strict_types=1);

namespace MaxRzDev\DummyJsonUserClient\Tests\Unit\Http;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use JsonException;
use MaxRzDev\DummyJsonUserClient\Exception\TransportException;
use MaxRzDev\DummyJsonUserClient\Exception\UserNotFoundException;
use MaxRzDev\DummyJsonUserClient\Http\Psr18ApiTransport;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

final class Psr18ApiTransportTest extends TestCase
{
    /**
     * @throws JsonException
     */
    public function testItSendsGetRequestsWithQueryParameters(): void
    {
        $client = new FakePsr18Client([
            new Response(200, [], json_encode(['id' => 1], JSON_THROW_ON_ERROR)),
        ]);
        $transport = $this->transport($client);

        self::assertSame(['id' => 1], $transport->get('/users/1', ['select' => 'id']));
        self::assertCount(1, $client->requests);
        self::assertSame('GET', $client->requests[0]->getMethod());
        self::assertSame('https://example.test/users/1?select=id', (string) $client->requests[0]->getUri());
        self::assertSame('application/json', $client->requests[0]->getHeaderLine('Accept'));
    }

    /**
     * @throws JsonException
     */
    public function testItSendsPostRequestsWithJsonPayload(): void
    {
        $client = new FakePsr18Client([
            new Response(200, [], json_encode(['id' => 209], JSON_THROW_ON_ERROR)),
        ]);
        $transport = $this->transport($client);

        self::assertSame(['id' => 209], $transport->post('/users/add', ['firstName' => 'John']));
        self::assertCount(1, $client->requests);
        self::assertSame('POST', $client->requests[0]->getMethod());
        self::assertSame('https://example.test/users/add', (string) $client->requests[0]->getUri());
        self::assertSame('application/json', $client->requests[0]->getHeaderLine('Accept'));
        self::assertSame('application/json', $client->requests[0]->getHeaderLine('Content-Type'));
        self::assertSame('{"firstName":"John"}', (string) $client->requests[0]->getBody());
    }

    public function testItPreservesErrorResponseContext(): void
    {
        $client = new FakePsr18Client([
            new Response(404, [], '{"message":"User not found"}'),
        ]);
        $transport = $this->transport($client);

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

    /**
     * @throws JsonException
     */
    public function testItRetriesNetworkFailuresForGets(): void
    {
        $client = new FakePsr18Client([
            new FakeNetworkException(new Request('GET', 'https://example.test/users/1')),
            new Response(200, [], json_encode(['ok' => true], JSON_THROW_ON_ERROR)),
        ]);
        $delays = [];
        $transport = $this->transport($client, maxRetries: 1, baseRetryDelayMilliseconds: 100, sleep: static function (int $delayMilliseconds) use (&$delays): void {
            $delays[] = $delayMilliseconds;
        });

        self::assertSame(['ok' => true], $transport->get('/users/1'));
        self::assertCount(2, $client->requests);
        self::assertSame([100], $delays);
    }

    /**
     * @throws JsonException
     */
    public function testItAppliesExponentialBackoffBetweenTemporaryGetFailures(): void
    {
        $client = new FakePsr18Client([
            new Response(500, [], '{}'),
            new Response(503, [], '{}'),
            new Response(200, [], json_encode(['ok' => true], JSON_THROW_ON_ERROR)),
        ]);
        $delays = [];
        $transport = $this->transport($client, maxRetries: 2, baseRetryDelayMilliseconds: 125, sleep: static function (int $delayMilliseconds) use (&$delays): void {
            $delays[] = $delayMilliseconds;
        });

        self::assertSame(['ok' => true], $transport->get('/users/1'));
        self::assertCount(3, $client->requests);
        self::assertSame([125, 250], $delays);
    }

    /**
     * @throws JsonException
     */
    public function testItUsesRetryAfterHeaderBeforeBackoff(): void
    {
        $client = new FakePsr18Client([
            new Response(429, ['Retry-After' => '2'], '{}'),
            new Response(200, [], json_encode(['ok' => true], JSON_THROW_ON_ERROR)),
        ]);
        $delays = [];
        $transport = $this->transport($client, maxRetries: 1, baseRetryDelayMilliseconds: 100, sleep: static function (int $delayMilliseconds) use (&$delays): void {
            $delays[] = $delayMilliseconds;
        });

        self::assertSame(['ok' => true], $transport->get('/users/1'));
        self::assertSame([2_000], $delays);
    }

    public function testItDoesNotRetryPostsAfterNetworkFailures(): void
    {
        $client = new FakePsr18Client([
            new FakeNetworkException(new Request('POST', 'https://example.test/users/add')),
            new Response(200, [], '{}'),
        ]);
        $transport = $this->transport($client, maxRetries: 2);

        try {
            $transport->post('/users/add', ['firstName' => 'John']);
            self::fail('Expected a transport exception.');
        } catch (TransportException $exception) {
            self::assertCount(1, $client->requests);
            self::assertInstanceOf(NetworkExceptionInterface::class, $exception->getPrevious());
        }
    }

    public function testItTranslatesNonNetworkClientFailures(): void
    {
        $client = new FakePsr18Client([
            new FakeClientException('Invalid request.'),
        ]);
        $transport = $this->transport($client, maxRetries: 2);

        try {
            $transport->get('/users/1');
            self::fail('Expected a transport exception.');
        } catch (TransportException $exception) {
            self::assertCount(1, $client->requests);
            self::assertInstanceOf(ClientExceptionInterface::class, $exception->getPrevious());
        }
    }

    private function transport(
        FakePsr18Client $client,
        int $maxRetries = 2,
        int $baseRetryDelayMilliseconds = 0,
        ?\Closure $sleep = null,
    ): Psr18ApiTransport {
        $httpFactory = new HttpFactory();

        return new Psr18ApiTransport(
            client: $client,
            requestFactory: $httpFactory,
            streamFactory: $httpFactory,
            baseUrl: 'https://example.test',
            maxRetries: $maxRetries,
            baseRetryDelayMilliseconds: $baseRetryDelayMilliseconds,
            sleep: $sleep,
        );
    }
}

final class FakePsr18Client implements ClientInterface
{
    /**
     * @var list<RequestInterface>
     */
    public array $requests = [];

    /**
     * @param list<ResponseInterface|ClientExceptionInterface> $queue
     */
    public function __construct(
        private array $queue,
    ) {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $next = array_shift($this->queue);

        if ($next instanceof ClientExceptionInterface) {
            throw $next;
        }

        if (! $next instanceof ResponseInterface) {
            throw new RuntimeException('Fake PSR-18 client queue is empty.');
        }

        return $next;
    }
}

final class FakeNetworkException extends RuntimeException implements NetworkExceptionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
    ) {
        parent::__construct('Network failure.');
    }

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}

final class FakeClientException extends RuntimeException implements ClientExceptionInterface
{
}
