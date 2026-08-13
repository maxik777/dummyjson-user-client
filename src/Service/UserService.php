<?php

declare(strict_types=1);

namespace MaxRzDev\DummyJsonUserClient\Service;

use GuzzleHttp\Client;
use MaxRzDev\DummyJsonUserClient\Contract\ApiTransportInterface;
use MaxRzDev\DummyJsonUserClient\Contract\UserServiceInterface;
use MaxRzDev\DummyJsonUserClient\DTO\PaginatedUsers;
use MaxRzDev\DummyJsonUserClient\DTO\User;
use MaxRzDev\DummyJsonUserClient\Exception\InvalidUserDataException;
use MaxRzDev\DummyJsonUserClient\Exception\UnexpectedResponseException;
use MaxRzDev\DummyJsonUserClient\Http\GuzzleApiTransport;

final readonly class UserService implements UserServiceInterface
{
    public function __construct(
        private ApiTransportInterface $transport,
    ) {
    }

    public static function createDefault(): self
    {
        return new self(new GuzzleApiTransport(new Client()));
    }

    public function getUser(int $id): User
    {
        if ($id < 1) {
            throw new InvalidUserDataException('User ID must be greater than zero.');
        }

        return $this->mapUser($this->transport->get(sprintf('/users/%d', $id)));
    }

    public function getUsers(int $page = 1, int $perPage = 20): PaginatedUsers
    {
        if ($page < 1) {
            throw new InvalidUserDataException('Page must be greater than zero.');
        }

        if ($perPage < 1) {
            throw new InvalidUserDataException('Per-page value must be greater than zero.');
        }

        $data = $this->transport->get('/users', [
            'limit' => $perPage,
            'skip' => ($page - 1) * $perPage,
            'select' => 'id,firstName,lastName,email',
        ]);

        if (! isset($data['users']) || ! is_array($data['users']) || ! array_is_list($data['users'])) {
            throw new UnexpectedResponseException('DummyJSON users response is missing a users list.');
        }

        if (! isset($data['total']) || ! is_int($data['total'])) {
            throw new UnexpectedResponseException('DummyJSON users response is missing a valid total.');
        }

        $users = array_map(
            fn (mixed $user): User => is_array($user)
                ? $this->mapUser($user)
                : throw new UnexpectedResponseException('DummyJSON users response contains a malformed user entry.'),
            $data['users'],
        );

        return new PaginatedUsers(
            users: $users,
            total: $data['total'],
            page: $page,
            perPage: $perPage,
        );
    }

    public function createUser(string $firstName, string $lastName, string $email): int
    {
        $firstName = trim($firstName);
        $lastName = trim($lastName);
        $email = trim($email);

        if ($firstName === '') {
            throw new InvalidUserDataException('First name must not be blank.');
        }

        if ($lastName === '') {
            throw new InvalidUserDataException('Last name must not be blank.');
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidUserDataException('Email must be syntactically valid.');
        }

        $data = $this->transport->post('/users/add', [
            'firstName' => $firstName,
            'lastName' => $lastName,
            'email' => $email,
        ]);

        if (! isset($data['id']) || ! is_int($data['id']) || $data['id'] < 1) {
            throw new UnexpectedResponseException('DummyJSON create-user response is missing a valid user ID.');
        }

        return $data['id'];
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function mapUser(array $data): User
    {
        if (! isset($data['id']) || ! is_int($data['id']) || $data['id'] < 1) {
            throw new UnexpectedResponseException('DummyJSON user response is missing a valid ID.');
        }

        if (! isset($data['firstName']) || ! is_string($data['firstName']) || $data['firstName'] === '') {
            throw new UnexpectedResponseException('DummyJSON user response is missing a valid first name.');
        }

        if (! isset($data['lastName']) || ! is_string($data['lastName']) || $data['lastName'] === '') {
            throw new UnexpectedResponseException('DummyJSON user response is missing a valid last name.');
        }

        if (! isset($data['email']) || ! is_string($data['email']) || $data['email'] === '') {
            throw new UnexpectedResponseException('DummyJSON user response is missing a valid email.');
        }

        return new User(
            id: $data['id'],
            firstName: $data['firstName'],
            lastName: $data['lastName'],
            email: $data['email'],
        );
    }
}
