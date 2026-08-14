<?php

declare(strict_types=1);

namespace MaxRzDev\DummyJsonUserClient\Http;

use GuzzleHttp\ClientInterface as GuzzleClientInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * @internal
 */
final readonly class GuzzlePsr18Client implements ClientInterface
{
    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        private GuzzleClientInterface $client,
        private array $options = [],
    ) {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        return $this->client->send($request, $this->options);
    }
}
