<?php

declare(strict_types=1);

namespace MaxRzDev\DummyJsonUserClient\Contract;

use MaxRzDev\DummyJsonUserClient\DTO\PaginatedUsers;
use MaxRzDev\DummyJsonUserClient\DTO\User;

interface UserServiceInterface
{
    public function getUser(int $id): User;

    public function getUsers(int $page = 1, int $perPage = 20): PaginatedUsers;

    public function createUser(string $firstName, string $lastName, string $email): int;
}
