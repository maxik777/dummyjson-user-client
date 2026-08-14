<?php

declare(strict_types=1);

namespace MaxRzDev\DummyJsonUserClient\Http;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\HttpFactory;

final class GuzzleApiTransport extends Psr18ApiTransport
{
    /**
     * @param Closure(int): void|null $sleep Receives a delay in milliseconds.
     */
    public function __construct(
        ClientInterface $client = new Client(),
        string $baseUrl = self::DEFAULT_BASE_URL,
        float $connectTimeout = 2.0,
        float $timeout = 5.0,
        int $maxRetries = 2,
        int $baseRetryDelayMilliseconds = 100,
        ?Closure $sleep = null,
    ) {
        $httpFactory = new HttpFactory();

        parent::__construct(
            client: new GuzzlePsr18Client($client, [
                'connect_timeout' => $connectTimeout,
                'timeout' => $timeout,
                'http_errors' => false,
            ]),
            requestFactory: $httpFactory,
            streamFactory: $httpFactory,
            baseUrl: $baseUrl,
            maxRetries: $maxRetries,
            baseRetryDelayMilliseconds: $baseRetryDelayMilliseconds,
            sleep: $sleep,
        );
    }
}
