<?php

declare(strict_types=1);

namespace MaxRzDev\DummyJsonUserClient\Http;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use MaxRzDev\DummyJsonUserClient\Contract\ApiTransportInterface;
use MaxRzDev\DummyJsonUserClient\Exception\ClientErrorException;
use MaxRzDev\DummyJsonUserClient\Exception\RateLimitException;
use MaxRzDev\DummyJsonUserClient\Exception\RemoteServerException;
use MaxRzDev\DummyJsonUserClient\Exception\TransportException;
use MaxRzDev\DummyJsonUserClient\Exception\UnexpectedResponseException;
use MaxRzDev\DummyJsonUserClient\Exception\UserNotFoundException;
use Psr\Http\Message\ResponseInterface;

final class GuzzleApiTransport implements ApiTransportInterface
{
    private const DEFAULT_BASE_URL = 'https://dummyjson.com';

    private const RETRYABLE_STATUS_CODES = [429, 500, 502, 503, 504];

    /**
     * @param Closure(int): void|null $sleep Receives a delay in milliseconds.
     */
    public function __construct(
        private readonly ClientInterface $client = new Client(),
        private readonly string $baseUrl = self::DEFAULT_BASE_URL,
        private readonly float $connectTimeout = 2.0,
        private readonly float $timeout = 5.0,
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
        return $this->request('GET', $path, [
            'query' => $query,
        ], retryTemporaryFailures: true);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function post(string $path, array $payload = []): array
    {
        return $this->request('POST', $path, [
            'json' => $payload,
        ], retryTemporaryFailures: false);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $options, bool $retryTemporaryFailures): array
    {
        $attempt = 0;

        while (true) {
            try {
                $response = $this->client->request($method, $this->urlFor($path), $this->options($options));
            } catch (GuzzleException $exception) {
                if ($retryTemporaryFailures && $attempt < $this->maxRetries) {
                    $this->delay($attempt, null);
                    ++$attempt;

                    continue;
                }

                throw new TransportException('Unable to communicate with DummyJSON.', previous: $exception);
            }

            $statusCode = $response->getStatusCode();

            if ($retryTemporaryFailures && $this->isRetryableStatusCode($statusCode) && $attempt < $this->maxRetries) {
                $this->delay($attempt, $response);
                ++$attempt;

                continue;
            }

            $this->throwForStatusCode($statusCode);

            return $this->decodeJson($response);
        }
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function options(array $options): array
    {
        return $options + [
            'connect_timeout' => $this->connectTimeout,
            'timeout' => $this->timeout,
            'http_errors' => false,
            'headers' => [
                'Accept' => 'application/json',
            ],
        ];
    }

    private function urlFor(string $path): string
    {
        return rtrim($this->baseUrl, '/').'/'.ltrim($path, '/');
    }

    private function throwForStatusCode(int $statusCode): void
    {
        if ($statusCode >= 200 && $statusCode < 300) {
            return;
        }

        if ($statusCode === 404) {
            throw new UserNotFoundException('The requested DummyJSON user was not found.');
        }

        if ($statusCode === 429) {
            throw new RateLimitException('DummyJSON rate limit was exceeded.');
        }

        if ($statusCode >= 500) {
            throw new RemoteServerException(sprintf('DummyJSON returned a server error with status code %d.', $statusCode));
        }

        if ($statusCode >= 400) {
            throw new ClientErrorException(sprintf('DummyJSON returned a client error with status code %d.', $statusCode));
        }

        throw new UnexpectedResponseException(sprintf('DummyJSON returned an unexpected status code %d.', $statusCode));
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
