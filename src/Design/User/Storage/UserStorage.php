<?php

declare(strict_types=1);

namespace Baraja\Cms;


interface UserStorage
{

	/**
	 * @param string $id
	 * @return User
	 * @throws UserManagerException
	 */
	public function load(string $id): User;

	/**
	 * @param string|null $primaryRole
	 * @param mixed[] $data
	 * @return User
	 * @throws UserManagerException
	 */
	public function create(?string $primaryRole = null, array $data = []): User;

	/**
	 * @param User $user
	 * @param string $key
	 * @param mixed $value
	 */
	public function setValue(User $user, string $key, $value): void;

	/**
	 * @param mixed $haystack
	 * @param string|null $key
	 * @param int $limit
	 * @return User[]
	 * @throws UserManagerException
	 */
	public function findByValue($haystack, ?string $key = null, int $limit = 10): array;

}