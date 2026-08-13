<?php

declare(strict_types=1);

namespace MaxRzDev\DummyJsonUserClient\Tests\Unit\DTO;

use JsonException;
use MaxRzDev\DummyJsonUserClient\DTO\PaginatedUsers;
use MaxRzDev\DummyJsonUserClient\DTO\User;
use PHPUnit\Framework\TestCase;

final class PaginatedUsersTest extends TestCase
{
    /**
     * @throws JsonException
     */
    public function testItConvertsToArrayAndJson(): void
    {
        $users = new PaginatedUsers(
            users: [
                new User(
                    id: 1,
                    firstName: 'John',
                    lastName: 'Doe',
                    email: 'john@example.com',
                ),
            ],
            total: 21,
            page: 2,
            perPage: 10,
        );

        $expected = [
            'users' => [
                [
                    'id' => 1,
                    'firstName' => 'John',
                    'lastName' => 'Doe',
                    'email' => 'john@example.com',
                ],
            ],
            'pagination' => [
                'page' => 2,
                'perPage' => 10,
                'total' => 21,
                'totalPages' => 3,
            ],
        ];

        self::assertSame(3, $users->totalPages());
        self::assertSame($expected, $users->toArray());
        self::assertSame(json_encode($expected, JSON_THROW_ON_ERROR), json_encode($users, JSON_THROW_ON_ERROR));
    }
}
