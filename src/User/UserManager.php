<?php

declare(strict_types=1);

namespace Baraja\Cms\User;


use Baraja\AdminBar\AdminIdentity;
use Baraja\BarajaCloud\CloudManager;
use Baraja\Cms\Helpers;
use Baraja\Cms\User\Entity\User;
use Baraja\Cms\User\Entity\UserLogin;
use Baraja\Cms\User\Entity\UserLoginAttempt;
use Baraja\Cms\User\Entity\UserMeta;
use Baraja\Doctrine\EntityManager;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Nette\Security\AuthenticationException;
use Nette\Security\Authenticator;
use Nette\Security\IIdentity;
use Nette\Security\Passwords;
use Nette\Security\UserStorage;

final class UserManager implements Authenticator
{
	private EntityManager $entityManager;

	private UserStorage $userStorage;

	private CloudManager $cloudManager;

	private ?AuthenticationService $authenticationService = null;


	public function __construct(EntityManager $entityManager, UserStorage $userStorage, CloudManager $cloudManager)
	{
		$this->entityManager = $entityManager;
		$this->userStorage = $userStorage;
		$this->cloudManager = $cloudManager;
	}


	/**
	 * @internal for DIC
	 */
	public function setAuthenticationService(AuthenticationService $authenticationService): void
	{
		$this->authenticationService = $authenticationService;
	}


	public function getIdentity(): ?IIdentity
	{
		return $this->userStorage->getState()[1];
	}


	public function createIdentity(IIdentity $user, string $expiration = '2 hours'): IIdentity
	{
		$name = null;
		$avatarUrl = null;
		if ($user instanceof User) {
			$name = $user->getName();
			$avatarUrl = $user->getAvatarUrl();
		}

		$identity = new AdminIdentity($user->getId(), $user->getRoles(), [], $name, $avatarUrl);
		$this->userStorage->saveAuthentication($identity);
		$this->userStorage->setExpiration($expiration, false);

		return $identity;
	}


	public function getUserStorage(): UserStorage
	{
		return $this->userStorage;
	}


	/**
	 * @throws AuthenticationException
	 */
	public function authenticate(string $username, string $password, bool $remember = false): IIdentity
	{
		$expiration = $remember ? '14 days' : '15 minutes';
		if (($username = trim($username)) === '' || ($password = trim($password)) === '') {
			throw new AuthenticationException('Username or password is empty.');
		}

		$attempt = new UserLoginAttempt(null, $username);
		$attempt->setLoginUrl(Helpers::getCurrentUrl());
		$this->entityManager->persist($attempt)->flush();

		if ($this->authenticationService !== null) {
			try {
				$identity = $this->authenticationService->authentication($username, $password);
				$this->logLoginAttempt($attempt, $identity);

				return $this->createIdentity($identity, $expiration);
			} catch (\Throwable $serviceException) {
				try {
					return $this->fallbackAuthenticate($attempt, $username, $password, $expiration);
				} catch (\Throwable $e) {
					throw new AuthenticationException($serviceException->getMessage(), $serviceException->getCode(), $serviceException);
				}
			}
		}

		return $this->fallbackAuthenticate($attempt, $username, $password, $expiration);
	}


	/**
	 * @throws AuthenticationException
	 * @deprecated use authenticate().
	 */
	public function login(string $username, string $password, bool $remember = false): IIdentity
	{
		return $this->authenticate($username, $password, $remember);
	}


	public function logout(): void
	{
		$this->userStorage->clearAuthentication(true);
		$this->userStorage->setExpiration(null, true);
	}


	/**
	 * @throws NoResultException|NonUniqueResultException
	 */
	public function getUserByUsername(string $username): User
	{
		return $this->entityManager->getRepository(User::class)
			->createQueryBuilder('user')
			->where('user.username = :username')
			->setParameter('username', $username)
			->getQuery()
			->setMaxResults(1)
			->getSingleResult();
	}


	/**
	 * @throws NoResultException|NonUniqueResultException
	 */
	public function getUserById(string $id): User
	{
		/** @var User[] $cache */
		static $cache = [];

		return $cache[$id] ?? $cache[$id] = $this->entityManager->getRepository(User::class)
				->createQueryBuilder('user')
				->where('user.id = :id')
				->setParameter('id', $id)
				->getQuery()
				->getSingleResult();
	}


	public function generateOtpCode(): string
	{
		try {
			$code = random_bytes(10);
		} catch (\Exception $e) {
			throw new \RuntimeException($e->getMessage(), $e->getCode(), $e);
		}

		return $code;
	}


	public function checkAuthenticatorOtpCode(User $user, int $code): bool
	{
		if (($otpCode = $user->getOtpCode()) === null) {
			return false;
		}

		return Helpers::checkAuthenticatorOtpCodeManually($otpCode, $code);
	}


	public function getMeta(string $userId, string $key): ?string
	{
		try {
			/** @var UserMeta $meta */
			$meta = $this->entityManager->getRepository(UserMeta::class)
				->createQueryBuilder('meta')
				->where('meta.user = :userId')
				->andWhere('meta.key = :key')
				->setParameters([
					'userId' => $userId,
					'key' => $key,
				])
				->setMaxResults(1)
				->getQuery()
				->getSingleResult();

			return $meta->getValue();
		} catch (NoResultException | NonUniqueResultException $e) {
		}

		return null;
	}


	public function setMeta(string $userId, string $key, ?string $value): self
	{
		try {
			/** @var UserMeta $meta */
			$meta = $this->entityManager->getRepository(UserMeta::class)
				->createQueryBuilder('meta')
				->where('meta.user = :userId')
				->andWhere('meta.key = :key')
				->setParameters([
					'userId' => $userId,
					'key' => $key,
				])
				->setMaxResults(1)
				->getQuery()
				->getSingleResult();
		} catch (NoResultException | NonUniqueResultException $e) {
			if ($value === null) {
				return $this;
			}
			try {
				$user = $this->getUserById($userId);
			} catch (NoResultException | NonUniqueResultException $eUser) {
				throw new \InvalidArgumentException('User "' . $userId . '" does not exist.', $e->getCode(), $e);
			}

			$this->entityManager->persist($meta = new UserMeta($user, $key, $value));
		}
		if ($value === null) {
			$this->entityManager->remove($meta);
		} else {
			$meta->setValue($value);
		}
		$this->entityManager->flush();

		return $this;
	}


	private function logLoginAttempt(UserLoginAttempt $attempt, IIdentity $identity): void
	{
		$attempt->setOkPassword();

		if ($identity instanceof User) {
			$attempt->setUser($identity);
			$this->entityManager->persist($userLogin = new UserLogin($identity));
			$identity->addLogin($userLogin);
		}

		$this->entityManager->flush();
	}


	/**
	 * @throws AuthenticationException
	 */
	private function fallbackAuthenticate(UserLoginAttempt $attempt, string $username, string $password, string $expiration): IIdentity
	{
		try {
			$user = $this->getUserByUsername($username);
		} catch (NoResultException | NonUniqueResultException $e) {
			// TODO: Reserved for future use
			// TODO: if (($externalEmail = $this->authenticateByCloudAccount($username, $password)) !== null) {
			// TODO: $user = $this->entityManager->persist($user = new User($username, $password, $externalEmail))->flush();
			// TODO: } else {
			throw new AuthenticationException('The username is incorrect. Username "' . $username . '" given.');
		}

		$attempt->setUser($user);
		$this->entityManager->flush();

		if (($hash = $user->getPassword()) === '---empty-password---') {
			throw new AuthenticationException('User password is empty or account is locked, please contact your administrator. Username "' . $username . '" given.');
		}
		if ($user->passwordVerify($password) === false) {
			throw new AuthenticationException('The password is incorrect. Username "' . $username . '" given.');
		}
		if ((new Passwords)->needsRehash($hash)) {
			$user->setPassword($password);
		}

		$this->logLoginAttempt($attempt, $user);

		return $this->createIdentity($user, $expiration);
	}


	/**
	 * Return user e-mail in case of this account exist and credentials match.
	 *
	 * @return null
	 */
	private function authenticateByCloudAccount(string $username, string $password)
	{
		// TODO: Implement me in future version.

		return null;
	}
}
