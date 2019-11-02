<?php

declare(strict_types=1);

namespace Baraja\Cms;


use Nette\Security\IIdentity;
use Nette\Security\Passwords;

class SimpleUserManager implements UserManager
{

	/**
	 * @var UserStorage
	 */
	private $userStorage;

	/**
	 * @var \Nette\Security\User
	 */
	private $user;

	/**
	 * @param UserStorage $userStorage
	 * @param \Nette\Security\User $user
	 */
	public function __construct(UserStorage $userStorage, \Nette\Security\User $user)
	{
		$this->userStorage = $userStorage;
		$this->user = $user;
	}

	/**
	 * @param string $id
	 * @return IIdentity
	 */
	public function getUserById(string $id): IIdentity
	{
		return $this->userStorage->load($id);
	}

	/**
	 * @param string $username
	 * @return IIdentity
	 */
	public function getUserByUsername(string $username): IIdentity
	{
		// TODO: Implement getUserByUsername() method.
	}


	/**
	 * @param IIdentity $user
	 * @param string $token
	 * @return bool
	 */
	public function verifyToken(IIdentity $user, string $token): bool
	{
		// TODO: Implement me!
	}

	/**
	 * @param string $username
	 * @param string $password
	 * @param bool $remember
	 * @return IIdentity
	 * @throws UserManagerException
	 */
	public function authenticate(string $username, string $password, bool $remember = false): IIdentity
	{
		if (($users = $this->userStorage->findByValue($username, 'username', 1)) === []) {
			throw new UserManagerException('User "' . $username . '" does not exist.');
		}

		$user = $users[0];
		$passwords = new Passwords;

		if ($passwords->verify($password, $user->getData()['password'] ?? '') === false) {
			throw new UserManagerException('User "' . $username . '" password does not match.');
		}

		if ($passwords->needsRehash($user->getData()['password'] ?? '') === true) {
			$this->userStorage->setValue($user, 'password', $passwords->hash($password));
		}

		$this->user->getStorage()
			->setAuthenticated(true)
			->setExpiration($remember === true ? '14 days' : '2 hours')
			->setIdentity($user);

		return $user;
	}

	/**
	 * @param IIdentity $user
	 * @param string $password
	 * @return bool
	 */
	public function checkPassword(IIdentity $user, string $password): bool
	{
		// TODO: Implement me!
	}

	/**
	 * @param string $id
	 * @return IIdentity
	 */
	public function createIdentity(string $id): IIdentity
	{
		// TODO: Implement me!
	}

	/**
	 * @param string $userId
	 * @return string|null
	 */
	public function getEmail(string $userId): ?string
	{
		// TODO: Implement me!
	}

	/**
	 * @param IIdentity $user
	 * @param string $key
	 * @param mixed $haystack
	 */
	public function setUserVariable(IIdentity $user, string $key, $haystack): void
	{
		if ($user instanceof User) {
			$this->userStorage->setValue($user, $key, $haystack);
		} else {
			$user->{$key} = $haystack;
		}
	}

	/**
	 * @param IIdentity $user
	 * @param string $newPassword
	 */
	public function changePassword(IIdentity $user, string $newPassword): void
	{
		// TODO: Implement me!
	}

}