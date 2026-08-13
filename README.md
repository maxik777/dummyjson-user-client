# DummyJSON User Client

Framework-agnostic PHP 8.4 Composer library for retrieving and creating users through the DummyJSON Users API.

This package intentionally does not depend on Laravel, Symfony, Drupal, WordPress, or any framework. It can be used from any PHP application that supports Composer.

## Installation

This take-home package is not published to Packagist. For review:

```bash
git clone https://github.com/maxik777/dummyjson-user-client.git
cd dummyjson-user-client
composer install
composer check
```

Another Composer project can install it from a VCS repository:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/maxik777/dummyjson-user-client"
        }
    ],
    "require": {
        "maxrzdev/dummyjson-user-client": "dev-main"
    }
}
```

## Usage

The simplest setup uses the default Guzzle-backed transport:

```php
<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';

use MaxRzDev\DummyJsonUserClient\Service\UserService;

$service = UserService::createDefault();

$user = $service->getUser(1);

print_r($user->toArray());
```

You can also inject the transport explicitly:

```php
use MaxRzDev\DummyJsonUserClient\Http\GuzzleApiTransport;
use MaxRzDev\DummyJsonUserClient\Service\UserService;

$service = new UserService(new GuzzleApiTransport());
```

Pagination exposes page/per-page input and converts it to DummyJSON's `limit` and `skip` parameters:

```php
$result = $service->getUsers(page: 2, perPage: 20);

foreach ($result->users as $user) {
    echo $user->email.PHP_EOL;
}
```

Creating a user returns the generated ID:

```php
$id = $service->createUser(
    firstName: 'John',
    lastName: 'Doe',
    email: 'john@example.com',
);
```

DummyJSON simulates user creation. The returned user is not permanently persisted by the remote API.

## DTOs

All returned users are mapped to immutable DTOs exposing only:

- `id`
- `firstName`
- `lastName`
- `email`

DTOs implement `JsonSerializable` and provide `toArray()`.

## Error Handling

Guzzle exceptions and raw HTTP details are translated at the package boundary:

```php
use MaxRzDev\DummyJsonUserClient\Exception\UserApiException;
use MaxRzDev\DummyJsonUserClient\Exception\UserNotFoundException;

try {
    $user = $service->getUser(999999);
} catch (UserNotFoundException $exception) {
    // Missing user.
} catch (UserApiException $exception) {
    // Remote API, transport, rate limit, server, or malformed response failure.
}
```

Package-level API failures extend `UserApiException`:

- `UserNotFoundException`
- `RateLimitException`
- `ClientErrorException`
- `RemoteServerException`
- `TransportException`
- `UnexpectedResponseException`

Invalid local input uses `InvalidUserDataException`, which extends PHP's `InvalidArgumentException`. That keeps caller validation failures separate from remote API failures.

## Retry Strategy

`GET` requests are retried conservatively for temporary failures:

- network/transport failure
- `429`
- `500`
- `502`
- `503`
- `504`

Retries are bounded and use a small exponential backoff. `Retry-After` is respected for rate-limited responses when present, capped to keep delays modest.

`POST /users/add` is intentionally not retried automatically because the remote API does not provide an idempotency guarantee. Blindly retrying writes could create duplicates against a real API.

## Testing

Unit tests are deterministic and make no network requests:

```bash
composer test
```

Run static analysis:

```bash
composer analyse
```

Run the normal review command:

```bash
composer check
```

Optional live integration tests are skipped by default:

```bash
RUN_INTEGRATION_TESTS=1 composer test-integration
```

These tests assert stable structure rather than mutable DummyJSON data values.

## Tooling

```bash
composer validate --strict
composer test
composer analyse
composer cs
composer check
```

The package targets PHP 8.4, uses `declare(strict_types=1);`, PSR-4 autoloading, PHPUnit, PHPStan, and PHP-CS-Fixer.
