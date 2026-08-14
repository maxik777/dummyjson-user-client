<?php

declare(strict_types=1);

namespace MaxRzDev\DummyJsonUserClient\Http;

use Closure;
use JsonException;
use MaxRzDev\DummyJsonUserClient\Contract\ApiTransportInterface;
use MaxRzDev\DummyJsonUserClient\Exception\ClientErrorException;
use MaxRzDev\DummyJsonUserClient\Exception\RateLimitException;
use MaxRzDev\DummyJsonUserClient\Exception\RemoteServerException;
use MaxRzDev\DummyJsonUserClient\Exception\TransportException;
use MaxRzDev\DummyJsonUserClient\Exception\UnexpectedResponseException;
use MaxRzDev\DummyJsonUserClient\Exception\UserNotFoundException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

class Psr18ApiTransport implements ApiTransportInterface
{
    protected const DEFAULT_BASE_URL = 'https://dummyjson.com';

    private const RETRYABLE_STATUS_CODES = [429, 500, 502, 503, 504];

    /**
     * @param Closure(int): void|null $sleep Receives a delay in milliseconds.
     */
    public function __construct(
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly string $baseUrl = self::DEFAULT_BASE_URL,
        private readonly int $maxRetries = 2,
        private readonly int $baseRetryDelayMilliseconds = 100,
        private readonly ?Closure $sleep = null,
    ) {
        if ($this->maxRetries < 0) {
            throw new \InvalidArgumentException('Maximum retry count must not be negative.');
        }

        if ($this->baseRetryDelayMilliseconds < 0) {
            throw new \InvalidArgumentException('Retry delay must not be negative.');
        }
    }

    /**
     * @param array<string, scalar|null> $query
     *
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = []): array
    {
        return $this->request('GET', $path, $query, null, retryTemporaryFailures: true);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function post(string $path, array $payload = []): array
    {
        return $this->request('POST', $path, [], $payload, retryTemporaryFailures: false);
    }

    /**
     * @param array<string, scalar|null> $query
     * @param array<string, mixed>|null $payload
     *
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $query, ?array $payload, bool $retryTemporaryFailures): array
    {
        $attempt = 0;

        while (true) {
            $request = $this->requestFactory
                ->createRequest($method, $this->urlFor($path, $query))
                ->withHeader('Accept', 'application/json');

            if ($payload !== null) {
                $request = $request
                    ->withHeader('Content-Type', 'application/json')
                    ->withBody($this->streamFactory->createStream($this->encodeJson($payload)));
            }

            try {
                $response = $this->client->sendRequest($request);
            } catch (NetworkExceptionInterface $exception) {
                if ($retryTemporaryFailures && $attempt < $this->maxRetries) {
                    $this->delay($attempt, null);
                    ++$attempt;

                    continue;
                }

                throw new TransportException('Unable to communicate with DummyJSON.', previous: $exception);
            } catch (ClientExceptionInterface $exception) {
                throw new TransportException('Unable to communicate with DummyJSON.', previous: $exception);
            }

            $statusCode = $response->getStatusCode();

            if ($retryTemporaryFailures && $this->isRetryableStatusCode($statusCode) && $attempt < $this->maxRetries) {
                $this->delay($attempt, $response);
                ++$attempt;

                continue;
            }

            $this->throwForResponse($response);

            return $this->decodeJson($response);
        }
    }

    /**
     * @param array<string, scalar|null> $query
     */
    private function urlFor(string $path, array $query = []): string
    {
        $url = rtrim($this->baseUrl, '/').'/'.ltrim($path, '/');
        $queryString = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        if ($queryString === '') {
            return $url;
        }

        return $url.(str_contains($url, '?') ? '&' : '?').$queryString;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encodeJson(array $payload): string
    {
        try {
            return json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new UnexpectedResponseException('Unable to encode request payload as JSON.', previous: $exception);
        }
    }

    private function throwForResponse(ResponseInterface $response): void
    {
        $statusCode = $response->getStatusCode();

        if ($statusCode >= 200 && $statusCode < 300) {
            return;
        }

        $responseBody = (string) $response->getBody();
        $apiMessage = $this->apiMessageFromResponseBody($responseBody);
        $messageSuffix = $apiMessage === null ? '' : sprintf(': %s', $apiMessage);

        if ($statusCode === 404) {
            throw new UserNotFoundException(
                sprintf('DummyJSON returned status code 404%s.', $messageSuffix),
                statusCode: $statusCode,
                responseBody: $responseBody,
                apiMessage: $apiMessage,
            );
        }

        if ($statusCode === 429) {
            throw new RateLimitException(
                sprintf('DummyJSON returned status code 429%s.', $messageSuffix),
                statusCode: $statusCode,
                responseBody: $responseBody,
                apiMessage: $apiMessage,
            );
        }

        if ($statusCode >= 500) {
            throw new RemoteServerException(
                sprintf('DummyJSON returned a server error with status code %d%s.', $statusCode, $messageSuffix),
                statusCode: $statusCode,
                responseBody: $responseBody,
                apiMessage: $apiMessage,
            );
        }

        if ($statusCode >= 400) {
            throw new ClientErrorException(
                sprintf('DummyJSON returned a client error with status code %d%s.', $statusCode, $messageSuffix),
                statusCode: $statusCode,
                responseBody: $responseBody,
                apiMessage: $apiMessage,
            );
        }

        throw new UnexpectedResponseException(
            sprintf('DummyJSON returned an unexpected status code %d%s.', $statusCode, $messageSuffix),
            statusCode: $statusCode,
            responseBody: $responseBody,
            apiMessage: $apiMessage,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(ResponseInterface $response): array
    {
        try {
            $decoded = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new UnexpectedResponseException('DummyJSON returned invalid JSON.', previous: $exception);
        }

        if (! is_array($decoded)) {
            throw new UnexpectedResponseException('DummyJSON returned a non-object JSON response.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function apiMessageFromResponseBody(string $responseBody): ?string
    {
        try {
            $decoded = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($decoded) || ! isset($decoded['message']) || ! is_string($decoded['message'])) {
            return null;
        }

        return $decoded['message'];
    }

    private function isRetryableStatusCode(int $statusCode): bool
    {
        return in_array($statusCode, self::RETRYABLE_STATUS_CODES, true);
    }

    private function delay(int $attempt, ?ResponseInterface $response): void
    {
        $delayMilliseconds = $this->retryAfterDelayMilliseconds($response)
            ?? $this->baseRetryDelayMilliseconds * (2 ** $attempt);

        if ($delayMilliseconds <= 0) {
            return;
        }

        if ($this->sleep !== null) {
            ($this->sleep)($delayMilliseconds);

            return;
        }

        usleep($delayMilliseconds * 1000);
    }

    private function retryAfterDelayMilliseconds(?ResponseInterface $response): ?int
    {
        if ($response === null || ! $response->hasHeader('Retry-After')) {
            return null;
        }

        $retryAfter = $response->getHeaderLine('Retry-After');

        if (ctype_digit($retryAfter)) {
            return min((int) $retryAfter * 1000, 2_000);
        }

        $retryAt = strtotime($retryAfter);

        if ($retryAt === false) {
            return null;
        }

        return min(max(0, ($retryAt - time()) * 1000), 2_000);
    }
}
