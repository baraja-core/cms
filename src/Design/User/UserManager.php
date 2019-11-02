<?php

declare(strict_types=1);

namespace Baraja\Cms;


use Nette\Security\IIdentity;

interface UserManager
{

	/**
	 * @param string $id
	 * @return IIdentity
	 */
	public function getUserById(string $id): IIdentity;

	/**
	 * @param string $username
	 * @return IIdentity
	 */
	public function getUserByUsername(string $username): IIdentity;

	/**
	 * @param IIdentity $user
	 * @param string $token
	 * @return bool
	 */
	public function verifyToken(IIdentity $user, string $token): bool;

	/**
	 * @param string $username
	 * @param string $password
	 * @param bool $remember
	 * @return IIdentity
	 * @throws UserManagerException
	 */
	public function authenticate(string $username, string $password, bool $remember = false): IIdentity;

	/**
	 * @param IIdentity $user
	 * @param string $password
	 * @return bool
	 */
	public function checkPassword(IIdentity $user, string $password): bool;

	/**
	 * @param string $id
	 * @return IIdentity
	 */
	public function createIdentity(string $id): IIdentity;

	/**
	 * @param string $userId
	 * @return string|null
	 */
	public function getEmail(string $userId): ?string;

	/**
	 * @param IIdentity $user
	 * @param string $key
	 * @param mixed $haystack
	 */
	public function setUserVariable(IIdentity $user, string $key, $haystack): void;

	/**
	 * @param IIdentity $user
	 * @param string $newPassword
	 */
	public function changePassword(IIdentity $user, string $newPassword): void;

}