<?php

declare(strict_types=1);

namespace MaxRzDev\DummyJsonUserClient\Contract;

interface ApiTransportInterface
{
    /**
     * @param array<string, scalar|null> $query
     *
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = []): array;

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function post(string $path, array $payload = []): array;
}
