<?php

declare(strict_types=1);

namespace MaxRzDev\DummyJsonUserClient\Tests\Integration;

use MaxRzDev\DummyJsonUserClient\Service\UserService;
use PHPUnit\Framework\TestCase;

final class DummyJsonIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        if (getenv('RUN_INTEGRATION_TESTS') !== '1') {
            self::markTestSkipped('Set RUN_INTEGRATION_TESTS=1 to run live DummyJSON integration tests.');
        }
    }

    public function testItRetrievesARealUser(): void
    {
        $user = UserService::createDefault()->getUser(1);

        self::assertGreaterThan(0, $user->id);
        self::assertNotSame('', $user->firstName);
        self::assertNotSame('', $user->lastName);
        self::assertNotSame('', $user->email);
    }

    public function testItRetrievesRealPaginatedUsers(): void
    {
        $users = UserService::createDefault()->getUsers(page: 1, perPage: 5);

        self::assertLessThanOrEqual(5, count($users->users));
        self::assertGreaterThanOrEqual(0, $users->total);
    }

    public function testItCreatesASimulatedUser(): void
    {
        $id = UserService::createDefault()->createUser(
            firstName: 'John',
            lastName: 'Doe',
            email: 'john@example.com',
        );

        self::assertGreaterThan(0, $id);
    }
}
