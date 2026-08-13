<?php

declare(strict_types=1);

namespace MaxRzDev\DummyJsonUserClient\Tests\Unit\DTO;

use JsonException;
use MaxRzDev\DummyJsonUserClient\DTO\User;
use PHPUnit\Framework\TestCase;

final class UserTest extends TestCase
{
    /**
     * @throws JsonException
     */
    public function testItConvertsToArrayAndJson(): void
    {
        $user = new User(
            id: 1,
            firstName: 'John',
            lastName: 'Doe',
            email: 'john@example.com',
        );

        $expected = [
            'id' => 1,
            'firstName' => 'John',
            'lastName' => 'Doe',
            'email' => 'john@example.com',
        ];

        self::assertSame($expected, $user->toArray());
        self::assertSame(json_encode($expected, JSON_THROW_ON_ERROR), json_encode($user, JSON_THROW_ON_ERROR));
    }
}
