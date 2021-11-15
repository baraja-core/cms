<?php

declare(strict_types=1);

namespace Baraja\Cms\User;


use Baraja\AdminBar\User\AdminIdentity;
use Baraja\Cms\Session;
use Baraja\Cms\User\Entity\CmsUser;
use Baraja\Cms\User\Entity\User;
use Baraja\Cms\User\Entity\UserLogin;
use Baraja\Cms\User\Entity\UserLoginAttempt;
use Baraja\Cms\User\Entity\UserLoginAttemptRepository;
use Baraja\DynamicConfiguration\Configuration;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Nette\Security\AuthenticationException;
use Nette\Security\Authenticator;
use Nette\Security\IIdentity;
use Nette\Security\UserStorage;

final class UserManager implements Authenticator
{
	private ?AuthenticationService $authenticationService = null;

	/** @var class-string<CmsUser> */
	private string $defaultEntity;

	private UserMetaManager $userMetaManager;


	public function __construct(
		private EntityManagerInterface $entityManager,
		private UserStorage $userStorage,
		private Configuration $configuration,
		?string $userEntity = null,
	) {
		$userEntity ??= User::class;
		if (is_subclass_of($userEntity, CmsUser::class) === false) {
			throw new \InvalidArgumentException(
				sprintf('User entity "%s" must implements "%s" interface.', $userEntity, CmsUser::class)
			);
		}
		$this->defaultEntity = $userEntity;
		$this->userMetaManager = new UserMetaManager($this->entityManager, $this);
	}


	public function isLoggedIn(): bool
	{
		return $this->userStorage->getState()[0];
	}


	public function isAdmin(): bool
	{
		$identity = $this->getIdentity();
		if ($identity !== null) {
			return in_array('admin', $identity->getRoles(), true);
		}

		return false;
	}


	/**
	 * @internal for DIC
	 */
	public function setAuthenticationService(AuthenticationService $authenticationService): void
	{
		$this->authenticationService = $authenticationService;
	}


	public function getUserMetaManager(): UserMetaManager
	{
		return $this->userMetaManager;
	}


	public function getIdentity(): ?CmsUser
	{
		$identity = $this->userStorage->getState()[1];
		$identifier = $identity !== null ? $identity->getId() : null;
		if (is_int($identifier) || is_string($identifier)) {
			return $this->getUserById((int) $identifier);
		}

		return null;
	}


	public function createUser(
		string $email,
		?string $password = null,
		?string $phone = null,
		?string $role = null,
	): CmsUser {
		if ($this->userExist($email) === true) {
			throw new \InvalidArgumentException(sprintf('User "%s" already exist.', $email));
		}
		$ref = new \ReflectionClass($this->getDefaultEntity());
		/** @var CmsUser $user */
		$user = $ref->newInstanceWithoutConstructor();
		$user->injectDefault(
			username: $email,
			password: $password ?? '',
			email: $email,
			role: CmsUser::ROLE_USER,
		);
		if ($phone !== null) {
			$user->setPhone($phone);
		}
		if ($role !== null) {
			$user->addRole($role);
		}
		$this->entityManager->persist($user);
		$this->entityManager->flush();

		return $user;
	}


	/**
	 * @deprecated since 2021-11-10, use getIdentity() instead.
	 */
	public function getCmsIdentity(): ?CmsUser
	{
		return $this->getIdentity();
	}


	/**
	 * @return class-string<CmsUser>
	 */
	public function getDefaultEntity(): string
	{
		return $this->defaultEntity;
	}


	public function getDefaultUserRepository(): EntityRepository
	{
		return new EntityRepository(
			$this->entityManager,
			$this->entityManager->getClassMetadata($this->defaultEntity)
		);
	}


	public function createLoginIdentity(IIdentity $user, string $expiration = '2 hours'): AdminIdentity
	{
		if (!$user instanceof CmsUser) {
			throw new \LogicException(
				sprintf('User identity must be instance of "%s", but "%s" given.', CmsUser::class, $user::class)
			);
		}
		if ($user->getOtpCode() !== null) { // need OTP authentication
			Session::set(Session::WORKFLOW_NEED_OTP_AUTH, true);
		}

		$identity = new AdminIdentity(
			id: $user->getId(),
			roles: $user->getRoles(),
			data: [],
			name: $user->getName(),
			avatarUrl: $user->getAvatarUrl(),
		);
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
		if ($this->isLoginFirewallBlocked($username) === true) {
			throw new AuthenticationException('Too many failed login attempts.', Authenticator::NOT_APPROVED);
		}
		$expiration = $remember ? '14 days' : '15 minutes';
		$username = trim($username);
		$password = trim($password);
		if ($username === '' || $password === '') {
			throw new AuthenticationException('Username or password is empty.', Authenticator::INVALID_CREDENTIAL);
		}

		$attempt = new UserLoginAttempt(null, $username);
		$this->entityManager->persist($attempt);
		$this->entityManager->flush();

		if ($this->authenticationService !== null) {
			try {
				$identity = $this->authenticationService->authentication($username, $password);
				$this->logLoginAttempt($attempt, $identity);

				return $this->createLoginIdentity($identity, $expiration);
			} catch (\Throwable $serviceException) {
				try {
					return $this->fallbackAuthenticate($attempt, $username, $password, $expiration);
				} catch (\Throwable) {
					throw new AuthenticationException($serviceException->getMessage(), 500, $serviceException);
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
		if (isset($_SESSION) && session_status() === PHP_SESSION_ACTIVE) {
			Session::removeAll();
			session_destroy();
		}
	}


	/**
	 * @throws NoResultException|NonUniqueResultException
	 */
	public function getUserByUsername(string $username): CmsUser
	{
		/** @phpstan-ignore-next-line */
		return $this->getDefaultUserRepository()
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
		/** @var array<int, CmsUser> $cache */
		static $cache = [];

		$find = function (int $id): CmsUser
		{
			/** @var CmsUser $entity */
			$entity = $this->getDefaultUserRepository()
				->createQueryBuilder('user')
				->where('user.id = :id')
				->setParameter('id', $id)
				->getQuery()
				->getSingleResult();

			return $entity;
		};

		return $cache[$id] ?? $cache[$id] = $find($id);
	}


	public function generateOtpCode(): string
	{
		try {
			$code = random_bytes(10);
		} catch (\Exception $e) {
			throw new \RuntimeException($e->getMessage(), 500, $e);
		}

		return $code;
	}


	/** @deprecated since 2021-05-01, use UserMetaManager instead. */
	public function getMeta(int $userId, string $key): ?string
	{
		trigger_error(__METHOD__ . ': This method is deprecated, use UserMetaManager instead.');

		return $this->userMetaManager->get($userId, $key);
	}


	/** @deprecated since 2021-05-01, use UserMetaManager instead. */
	public function setMeta(int $userId, string $key, ?string $value): self
	{
		trigger_error(__METHOD__ . ': This method is deprecated, use UserMetaManager instead.');
		$this->userMetaManager->set($userId, $key, $value);

		return $this;
	}


	public function isOnline(int $userId): bool
	{
		$lastActivity = $this->userMetaManager->get($userId, 'last-activity');
		if ($lastActivity === null) {
			return false;
		}

		return (new \DateTime($lastActivity))->getTimestamp() + 30 >= time();
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
			Session::set(Session::LAST_IDENTITY_ID, $currentIdentity->getId());
		}
		$this->createLoginIdentity($user);
		Session::remove(Session::WORKFLOW_NEED_OTP_AUTH);
	}


	public function isLoginFirewallBlocked(string $username, ?string $ip = null): bool
	{
		if (PHP_SAPI === 'cli') {
			return false;
		}
		$blockCountKey = 'user-login-attempts-block-count';
		$blockIntervalKey = 'user-login-attempts-block-interval';

		$configuration = $this->configuration->getSection('core');
		$blockCount = $configuration->get($blockCountKey);
		if ($blockCount === null) {
			$blockCount = 10;
			$configuration->save($blockCountKey, (string) $blockCount);
		}
		$blockInterval = $configuration->get($blockIntervalKey);
		if ($blockInterval === null) {
			$blockInterval = '20 minutes';
			$configuration->save($blockIntervalKey, $blockInterval);
		}

		/** @var UserLoginAttemptRepository $attemptRepository */
		$attemptRepository = $this->entityManager->getRepository(UserLoginAttemptRepository::class);
		$attempts = $attemptRepository->getUsedAttempts($username, $blockInterval, $ip, (int) $blockCount);

		return count($attempts) >= (int) $blockCount;
	}


	public function userExist(string $email): bool
	{
		try {
			$this->getDefaultUserRepository()
				->createQueryBuilder('user')
				->select('PARTIAL user.{id}')
				->where('user.username = :username')
				->setParameter('username', $email)
				->setMaxResults(1)
				->getQuery()
				->getSingleResult();

			return true;
		} catch (NoResultException | NonUniqueResultException) {
		}

		return false;
	}


	public function getCountUsers(): int
	{
		try {
			$return = $this->getDefaultUserRepository()
				->createQueryBuilder('user')
				->select('COUNT(user.id)')
				->getQuery()
				->getSingleScalarResult();
			if (is_numeric($return)) {
				return (int) $return;
			}
		} catch (\Throwable) {
			// Silence is golden.
		}

		throw new \LogicException('Can not count users.');
	}


	private function logLoginAttempt(UserLoginAttempt $attempt, IIdentity $identity): void
	{
		$attempt->setOkPassword();

		if ($identity instanceof User) {
			$attempt->setUser($identity);
			$userLogin = new UserLogin($identity);
			$this->entityManager->persist($userLogin);
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
				sprintf('The username is incorrect. Username "%s" given.', $username),
				Authenticator::IDENTITY_NOT_FOUND,
			);
		}
		if ($user instanceof User) {
			$attempt->setUser($user);
		}
		$this->entityManager->flush();

		if ($this->userMetaManager->get($user->getId(), 'blocked') === 'true') {
			throw new AuthenticationException(
				$this->userMetaManager->get($user->getId(), 'block-reason') ?? '',
				Authenticator::NOT_APPROVED,
			);
		}

		$hash = $user->getPassword();

		if ($hash === '---empty-password---') {
			throw new AuthenticationException(
				sprintf(
					'User password is empty or account is locked, please contact your administrator. Username "%s" given.',
					$username
				),
				Authenticator::FAILURE,
			);
		}
		if ($user->passwordVerify($password) === false) {
			throw new AuthenticationException(sprintf('The password is incorrect. Username "%s" given.', $username));
		}
		if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
			try {
				$user->setPassword($password);
			} catch (\InvalidArgumentException) {
				// Silence is golden.
			}
		}

		$this->logLoginAttempt($attempt, $user);

		return $this->createLoginIdentity($user, $expiration);
	}
}
