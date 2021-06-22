<?php

declare(strict_types=1);

namespace Baraja\Cms\User;


use Baraja\AdminBar\User\AdminIdentity;
use Baraja\Cms\Session;
use Baraja\Cms\User\Entity\CmsUser;
use Baraja\Cms\User\Entity\User;
use Baraja\Cms\User\Entity\UserLogin;
use Baraja\Cms\User\Entity\UserLoginAttempt;
use Baraja\Doctrine\EntityManager;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Nette\Security\AuthenticationException;
use Nette\Security\Authenticator;
use Nette\Security\IIdentity;
use Nette\Security\Passwords;
use Nette\Security\UserStorage;
use Nette\Utils\DateTime;

final class UserManager implements Authenticator
{
	private ?AuthenticationService $authenticationService = null;

	private string $defaultEntity;

	private UserMetaManager $userMetaManager;


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
		$this->userMetaManager = new UserMetaManager($this->entityManager, $this);
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


	public function getIdentity(): ?IIdentity
	{
		return $this->userStorage->getState()[1];
	}


	public function getCmsIdentity(): ?CmsUser
	{
		$identity = $this->getIdentity();
		if ($identity !== null) {
			return $this->getUserById($identity->getId());
		}

		return null;
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
			if ($user->getOtpCode() !== null) { // need OTP authentication
				Session::set(Session::WORKFLOW_NEED_OTP_AUTH, true);
			}
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

		return DateTime::from($lastActivity)->getTimestamp() + 30 >= time();
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
		$this->createIdentity($user);
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

		/** @var array<int, array<string, int>> $attempts */
		$attempts = $this->entityManager->getRepository(UserLoginAttempt::class)
			->createQueryBuilder('login')
			->select('PARTIAL login.{id}')
			->leftJoin('login.user', 'user')
			->where('login.user IS NULL OR user.username = :username OR user.email = :username OR login.username = :username OR login.ip = :ip')
			->andWhere('login.insertedDateTime >= :intervalDate')
			->andWhere('login.password = FALSE')
			->setParameter('username', $username)
			->setParameter('ip', $ip ?? Helpers::userIp())
			->setParameter('intervalDate', DateTime::from('now - ' . $blockInterval))
			->setMaxResults(((int) $blockCount) * 2)
			->getQuery()
			->getArrayResult();

		return count($attempts) >= (int) $blockCount;
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
				'The username is incorrect. Username "' . $username . '" given.',
				Authenticator::IDENTITY_NOT_FOUND,
			);
		}
		if ($user instanceof User) {
			$attempt->setUser($user);
		}
		$this->entityManager->flush();

		if ($this->userMetaManager->get((int) $user->getId(), 'blocked') === 'true') {
			throw new AuthenticationException(
				$this->userMetaManager->get((int) $user->getId(), 'block-reason') ?? '',
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
			try {
				$user->setPassword($password);
			} catch (\InvalidArgumentException) {
				// Silence is golden.
			}
		}

		$this->logLoginAttempt($attempt, $user);

		return $this->createIdentity($user, $expiration);
	}
}
