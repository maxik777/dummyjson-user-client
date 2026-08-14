<?php

declare(strict_types=1);

namespace MaxRzDev\DummyJsonUserClient\Exception;

use RuntimeException;
use Throwable;

abstract class UserApiException extends RuntimeException
{
    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        private readonly ?int $statusCode = null,
        private readonly ?string $responseBody = null,
        private readonly ?string $apiMessage = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function statusCode(): ?int
    {
        return $this->statusCode;
    }

    public function responseBody(): ?string
    {
        return $this->responseBody;
    }

    public function apiMessage(): ?string
    {
        return $this->apiMessage;
    }
}
