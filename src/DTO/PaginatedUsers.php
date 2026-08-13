<?php

declare(strict_types=1);

namespace MaxRzDev\DummyJsonUserClient\DTO;

use JsonSerializable;

final readonly class PaginatedUsers implements JsonSerializable
{
    /**
     * @param list<User> $users
     */
    public function __construct(
        public array $users,
        public int $total,
        public int $page,
        public int $perPage,
    ) {
    }

    public function totalPages(): int
    {
        return (int) ceil($this->total / $this->perPage);
    }

    /**
     * @return array{
     *     users: list<array{id: int, firstName: string, lastName: string, email: string}>,
     *     pagination: array{page: int, perPage: int, total: int, totalPages: int}
     * }
     */
    public function toArray(): array
    {
        return [
            'users' => array_map(
                static fn (User $user): array => $user->toArray(),
                $this->users,
            ),
            'pagination' => [
                'page' => $this->page,
                'perPage' => $this->perPage,
                'total' => $this->total,
                'totalPages' => $this->totalPages(),
            ],
        ];
    }

    /**
     * @return array{
     *     users: list<array{id: int, firstName: string, lastName: string, email: string}>,
     *     pagination: array{page: int, perPage: int, total: int, totalPages: int}
     * }
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
