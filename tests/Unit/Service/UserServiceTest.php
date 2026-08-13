<?php

declare(strict_types=1);

namespace MaxRzDev\DummyJsonUserClient\Tests\Unit\Service;

use MaxRzDev\DummyJsonUserClient\Contract\ApiTransportInterface;
use MaxRzDev\DummyJsonUserClient\DTO\User;
use MaxRzDev\DummyJsonUserClient\Exception\InvalidUserDataException;
use MaxRzDev\DummyJsonUserClient\Exception\TransportException;
use MaxRzDev\DummyJsonUserClient\Exception\UnexpectedResponseException;
use MaxRzDev\DummyJsonUserClient\Exception\UserNotFoundException;
use MaxRzDev\DummyJsonUserClient\Service\UserService;
use PHPUnit\Framework\TestCase;

final class UserServiceTest extends TestCase
{
    public function testItRetrievesAUser(): void
    {
        $transport = new FakeApiTransport();
        $transport->getResponse = [
            'id' => 123,
            'firstName' => 'Jane',
            'lastName' => 'Doe',
            'email' => 'jane@example.com',
            'age' => 40,
        ];

        $user = (new UserService($transport))->getUser(123);

        self::assertInstanceOf(User::class, $user);
        self::assertSame(123, $user->id);
        self::assertSame('Jane', $user->firstName);
        self::assertSame('Doe', $user->lastName);
        self::assertSame('jane@example.com', $user->email);
        self::assertSame('/users/123', $transport->lastGetPath);
        self::assertSame([], $transport->lastGetQuery);
    }

    public function testItRejectsInvalidUserId(): void
    {
        $this->expectException(InvalidUserDataException::class);

        (new UserService(new FakeApiTransport()))->getUser(0);
    }

    public function testItRejectsMalformedUserResponses(): void
    {
        $transport = new FakeApiTransport();
        $transport->getResponse = [
            'id' => 123,
            'firstName' => 'Jane',
            'lastName' => 'Doe',
        ];

        $this->expectException(UnexpectedResponseException::class);

        (new UserService($transport))->getUser(123);
    }

    public function testItPropagatesPackageApiExceptions(): void
    {
        $transport = new FakeApiTransport();
        $transport->getException = new UserNotFoundException('Not found.');

        $this->expectException(UserNotFoundException::class);

        (new UserService($transport))->getUser(999);
    }

    public function testItPropagatesTransportFailures(): void
    {
        $transport = new FakeApiTransport();
        $transport->getException = new TransportException('Network failure.');

        $this->expectException(TransportException::class);

        (new UserService($transport))->getUser(1);
    }

    public function testItRetrievesPaginatedUsers(): void
    {
        $transport = new FakeApiTransport();
        $transport->getResponse = [
            'users' => [
                [
                    'id' => 1,
                    'firstName' => 'Jane',
                    'lastName' => 'Doe',
                    'email' => 'jane@example.com',
                ],
                [
                    'id' => 2,
                    'firstName' => 'John',
                    'lastName' => 'Smith',
                    'email' => 'john@example.com',
                ],
            ],
            'total' => 100,
            'limit' => 25,
            'skip' => 50,
        ];

        $users = (new UserService($transport))->getUsers(page: 3, perPage: 25);

        self::assertSame('/users', $transport->lastGetPath);
        self::assertSame([
            'limit' => 25,
            'skip' => 50,
            'select' => 'id,firstName,lastName,email',
        ], $transport->lastGetQuery);
        self::assertCount(2, $users->users);
        self::assertSame(100, $users->total);
        self::assertSame(3, $users->page);
        self::assertSame(25, $users->perPage);
        self::assertSame(4, $users->totalPages());
    }

    public function testItSupportsEmptyPaginatedResults(): void
    {
        $transport = new FakeApiTransport();
        $transport->getResponse = [
            'users' => [],
            'total' => 0,
        ];

        $users = (new UserService($transport))->getUsers();

        self::assertSame([], $users->users);
        self::assertSame(0, $users->total);
    }

    public function testItRejectsInvalidPaginationInput(): void
    {
        $this->expectException(InvalidUserDataException::class);

        (new UserService(new FakeApiTransport()))->getUsers(page: 0);
    }

    public function testItRejectsMalformedPaginatedResponses(): void
    {
        $transport = new FakeApiTransport();
        $transport->getResponse = [
            'users' => 'not a list',
            'total' => 100,
        ];

        $this->expectException(UnexpectedResponseException::class);

        (new UserService($transport))->getUsers();
    }

    public function testItRejectsMalformedPaginatedUserEntries(): void
    {
        $transport = new FakeApiTransport();
        $transport->getResponse = [
            'users' => [
                [
                    'id' => 1,
                    'firstName' => 'Jane',
                    'lastName' => 'Doe',
                ],
            ],
            'total' => 1,
        ];

        $this->expectException(UnexpectedResponseException::class);

        (new UserService($transport))->getUsers();
    }

    public function testItCreatesAUserAndReturnsTheId(): void
    {
        $transport = new FakeApiTransport();
        $transport->postResponse = [
            'id' => 209,
            'firstName' => 'John',
            'lastName' => 'Doe',
            'email' => 'john@example.com',
        ];

        $id = (new UserService($transport))->createUser(
            firstName: ' John ',
            lastName: ' Doe ',
            email: ' john@example.com ',
        );

        self::assertSame(209, $id);
        self::assertSame('/users/add', $transport->lastPostPath);
        self::assertSame([
            'firstName' => 'John',
            'lastName' => 'Doe',
            'email' => 'john@example.com',
        ], $transport->lastPostPayload);
    }

    public function testItRejectsInvalidCreateUserInput(): void
    {
        $this->expectException(InvalidUserDataException::class);

        (new UserService(new FakeApiTransport()))->createUser(
            firstName: 'John',
            lastName: 'Doe',
            email: 'not-an-email',
        );
    }

    public function testItRejectsCreateUserResponsesWithoutAValidId(): void
    {
        $transport = new FakeApiTransport();
        $transport->postResponse = [
            'id' => '209',
        ];

        $this->expectException(UnexpectedResponseException::class);

        (new UserService($transport))->createUser(
            firstName: 'John',
            lastName: 'Doe',
            email: 'john@example.com',
        );
    }
}

final class FakeApiTransport implements ApiTransportInterface
{
    /**
     * @var array<string, mixed>
     */
    public array $getResponse = [];

    /**
     * @var array<string, mixed>
     */
    public array $postResponse = [];

    public ?\Throwable $getException = null;

    public ?string $lastGetPath = null;

    /**
     * @var array<string, scalar|null>
     */
    public array $lastGetQuery = [];

    public ?string $lastPostPath = null;

    /**
     * @var array<string, mixed>
     */
    public array $lastPostPayload = [];

    /**
     * @param array<string, scalar|null> $query
     *
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = []): array
    {
        $this->lastGetPath = $path;
        $this->lastGetQuery = $query;

        if ($this->getException !== null) {
            throw $this->getException;
        }

        return $this->getResponse;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function post(string $path, array $payload = []): array
    {
        $this->lastPostPath = $path;
        $this->lastPostPayload = $payload;

        return $this->postResponse;
    }
}
