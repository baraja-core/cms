<?php

declare(strict_types=1);

namespace Baraja\Cms\User;


use Baraja\AdminBar\User\AdminIdentity;
use Baraja\Cms\Helpers;
use Baraja\Cms\User\Entity\CmsUser;
use Baraja\Cms\User\Entity\User;
use Baraja\Cms\User\Entity\UserLogin;
use Baraja\Cms\User\Entity\UserLoginAttempt;
use Baraja\Cms\User\Entity\UserMeta;
use Baraja\Doctrine\EntityManager;
use Baraja\Url\Url;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Nette\Security\AuthenticationException;
use Nette\Security\Authenticator;
use Nette\Security\IIdentity;
use Nette\Security\Passwords;
use Nette\Security\UserStorage;

final class UserManager implements Authenticator
{
	public const LAST_IDENTITY_ID__SESSION_KEY = '__BRJ_CMS--last-identity-id';

	private ?AuthenticationService $authenticationService = null;

	private string $defaultEntity;


	public function __construct(
		private EntityManager $entityManager,
		private UserStorage $userStorage,
		?string $userEntity = null,
	) {
		$userEntity ??= User::class;
		if (is_subclass_of($userEntity, CmsUser::class) === false) {
			throw new \InvalidArgumentException('User entity "' . $userEntity . '" must implements "' . CmsUser::class . '" interface.');
		}
		$this->defaultEntity = $userEntity;
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


	public function getDefaultEntity(): string
	{
		return $this->defaultEntity;
	}


	public function createIdentity(IIdentity $user, string $expiration = '2 hours'): IIdentity
	{
		$name = null;
		$avatarUrl = null;
		if ($user instanceof CmsUser) {
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
		$username = trim($username);
		$password = trim($password);
		if ($username === '' || $password === '') {
			throw new AuthenticationException('Username or password is empty.', Authenticator::INVALID_CREDENTIAL);
		}

		$attempt = new UserLoginAttempt(null, $username);
		$this->entityManager->persist($attempt)->flush();

		if ($this->authenticationService !== null) {
			try {
				$identity = $this->authenticationService->authentication($username, $password);
				$this->logLoginAttempt($attempt, $identity);

				return $this->createIdentity($identity, $expiration);
			} catch (\Throwable $serviceException) {
				try {
					return $this->fallbackAuthenticate($attempt, $username, $password, $expiration);
				} catch (\Throwable) {
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
		if (isset($_SESSION) && session_status() === PHP_SESSION_ACTIVE) {
			unset($_SESSION[self::LAST_IDENTITY_ID__SESSION_KEY]);
		}
		$this->userStorage->clearAuthentication(true);
		$this->userStorage->setExpiration(null, true);
	}


	/**
	 * @throws NoResultException|NonUniqueResultException
	 */
	public function getUserByUsername(string $username): CmsUser
	{
		return $this->entityManager->getRepository($this->defaultEntity)
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
	public function getUserById(int $id): CmsUser
	{
		/** @var CmsUser[] $cache */
		static $cache = [];

		return $cache[$id] ?? $cache[$id] = $this->entityManager->getRepository($this->defaultEntity)
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


	public function checkAuthenticatorOtpCode(CmsUser $user, int $code): bool
	{
		if (($otpCode = $user->getOtpCode()) === null) {
			return false;
		}

		return Helpers::checkAuthenticatorOtpCodeManually($otpCode, $code);
	}


	public function getMeta(int $userId, string $key): ?string
	{
		try {
			/** @var UserMeta $meta */
			$meta = $this->entityManager->getRepository(UserMeta::class)
				->createQueryBuilder('meta')
				->where('meta.user = :userId')
				->andWhere('meta.key = :key')
				->setParameter('userId', $userId)
				->setParameter('key', $key)
				->setMaxResults(1)
				->getQuery()
				->getSingleResult();

			return $meta->getValue();
		} catch (NoResultException | NonUniqueResultException) {
		}

		return null;
	}


	public function setMeta(int $userId, string $key, ?string $value): self
	{
		try {
			/** @var UserMeta $meta */
			$meta = $this->entityManager->getRepository(UserMeta::class)
				->createQueryBuilder('meta')
				->where('meta.user = :userId')
				->andWhere('meta.key = :key')
				->setParameter('userId', $userId)
				->setParameter('key', $key)
				->setMaxResults(1)
				->getQuery()
				->getSingleResult();
		} catch (NoResultException | NonUniqueResultException $e) {
			if ($value === null) {
				return $this;
			}
			try {
				/** @var User $user */
				$user = $this->getUserById($userId);
			} catch (NoResultException | NonUniqueResultException) {
				throw new \InvalidArgumentException('User "' . $userId . '" does not exist.', $e->getCode(), $e);
			}

			$meta = new UserMeta($user, $key, $value);
			$this->entityManager->persist($meta);
		}
		if ($value === null) {
			$this->entityManager->remove($meta);
		} else {
			$meta->setValue($value);
		}
		$this->entityManager->flush();

		return $this;
	}


	public function loginAs(int $id): void
	{
		$currentIdentity = $this->getIdentity();
		if ($currentIdentity === null || $currentIdentity->getId() === $id) {
			return;
		}
		try {
			$user = $this->getUserById($id);
		} catch (NoResultException | NonUniqueResultException) {
			throw new \InvalidArgumentException('User "' . $id . '" does not exist.');
		}
		if (isset($_SESSION) && session_status() === PHP_SESSION_ACTIVE) {
			$_SESSION[self::LAST_IDENTITY_ID__SESSION_KEY] = $currentIdentity->getId();
		}
		$this->createIdentity($user);
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
	private function fallbackAuthenticate(
		UserLoginAttempt $attempt,
		string $username,
		string $password,
		string $expiration
	): IIdentity {
		try {
			$user = $this->getUserByUsername($username);
		} catch (NoResultException | NonUniqueResultException) {
			throw new AuthenticationException(
				'The username is incorrect. Username "' . $username . '" given.',
				Authenticator::IDENTITY_NOT_FOUND,
			);
		}
		if ($user instanceof User) {
			$attempt->setUser($user);
		}
		$this->entityManager->flush();

		if ($this->getMeta((int) $user->getId(), 'blocked') === 'true') {
			throw new AuthenticationException(
				$this->getMeta((int) $user->getId(), 'block-reason') ?? '',
				Authenticator::NOT_APPROVED,
			);
		}

		$hash = $user->getPassword();

		if ($hash === '---empty-password---') {
			throw new AuthenticationException(
				'User password is empty or account is locked, please contact your administrator. Username "' . $username . '" given.',
				Authenticator::FAILURE,
			);
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
}
