<?php

declare(strict_types=1);

namespace Baraja\Cms\Api;


use Baraja\BarajaCloud\CloudManager;
use Baraja\Cms\Helpers;
use Baraja\Cms\User\Entity\User;
use Baraja\Cms\User\Entity\UserLogin;
use Baraja\Cms\User\Entity\UserMeta;
use Baraja\Cms\User\UserManager;
use Baraja\Doctrine\EntityManager;
use Baraja\StructuredApi\BaseEndpoint;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Nette\Caching\Cache;
use Nette\Http\Url;
use Nette\Utils\DateTime;
use Nette\Utils\Paginator;
use Nette\Utils\Random;
use Nette\Utils\Strings;
use Nette\Utils\Validators;

final class UserEndpoint extends BaseEndpoint
{

	/**
	 * @var UserManager
	 * @inject
	 */
	public $userManager;

	/**
	 * @var EntityManager
	 * @inject
	 */
	public $entityManager;

	/**
	 * @var CloudManager
	 * @inject
	 */
	public $cloudManager;


	/**
	 * Returns all users
	 *
	 * @param int $page
	 * @param int $limit
	 * @param string|null $role
	 * @param string|null $query
	 * @param string|null $active String 'active' => show only active users, 'deleted' => show only deleted users, null => all users
	 */
	public function actionDefault(int $page = 1, int $limit = 32, ?string $role = null, ?string $query = null, ?string $active = null): void
	{
		$selection = $this->entityManager->getRepository(User::class)->createQueryBuilder('user');
		if ($active !== null) {
			$selection->andWhere('user.active = ' . ($active === 'active' ? 'TRUE' : 'FALSE'));
		}

		$allRoles = $this->getAllRoles();
		if ($role !== null) {
			if (isset($allRoles[$role]) === false) {
				$this->sendError('Role "' . $role . '" does not exist. Did you mean "' . implode('", "', $allRoles) . '"?');
			}
			$selection->andWhere('user.roles LIKE :role')
				->setParameter('role', '%"' . $role . '"%');
		}

		if ($query !== null) {
			if (preg_match('/^(\S+)\s+(.+)$/', $query = trim((string) preg_replace('/\s+/', ' ', $query)), $queryParser)) {
				$selection->andWhere('user.username LIKE :firstName OR user.username LIKE :lastName OR user.firstName LIKE :firstName OR user.lastName LIKE :lastName OR user.emails LIKE :firstName OR user.emails LIKE :lastName')
					->setParameter('firstName', $queryParser[1] . '%')
					->setParameter('lastName', $queryParser[2] . '%');
			} else {
				$selection->andWhere('user.username LIKE :query OR user.firstName LIKE :query OR user.lastName LIKE :query OR user.emails LIKE :query OR user.phone LIKE :query')
					->setParameter('query', '%' . $query . '%');
			}
		}

		$users = $selection->select('PARTIAL user.{id, firstName, lastName, emails, phone, roles, active, avatarUrl}')
			->setMaxResults($limit)
			->setFirstResult(($page - 1) * $limit)
			->orderBy('user.createDate', 'DESC')
			->getQuery()
			->getArrayResult();

		$return = [];
		foreach ($users as $user) {
			$return[] = [
				'id' => $user['id'],
				'name' => (function () use ($user): ?string {
					if (($return = trim($user['firstName'] . ' ' . $user['lastName']) ?: null) === null) {
						$return = explode('@', $user['emails'][0] ?? '')[0] ?? null;
					}

					return Strings::firstUpper((string) $return) ?: null;
				})(),
				'email' => $user['emails'][0] ?? null,
				'roles' => $user['roles'],
				'phone' => $user['phone'],
				'isActive' => $user['active'],
				'avatarUrl' => $user['avatarUrl'],
			];
		}

		$this->sendJson([
			'list' => $return,
			'roles' => $this->formatBootstrapSelectArray($allRoles),
			'statusCount' => [
				'all' => \count($allUsers = $this->getAllUsers()),
				'active' => \count(array_filter($allUsers, static function (array $item): bool {
					return $item['active'] === true;
				})),
				'deleted' => \count(array_filter($allUsers, static function (array $item): bool {
					return $item['active'] === false;
				})),
			],
			'paginator' => (new Paginator)
				->setItemCount(\count($allUsers))
				->setItemsPerPage($limit)
				->setPage($page),
		]);
	}


	public function createDefault(string $fullName, string $email, string $role, ?string $phone = null, ?string $password = null): void
	{
		if ($this->userExist($email) === true) {
			$this->sendError('User "' . $email . '" already exist.');
		}
		try {
			$this->entityManager->persist($user = new User($email, $password ?? '', $email, User::ROLE_USER));
			$user->setPhone($phone);
			$user->addRole($role);
			$this->entityManager->flush($user);
		} catch (\InvalidArgumentException $e) {
			$this->sendError($e->getMessage());

			return;
		}

		$this->setRealUserName($user, $fullName);
		$this->entityManager->flush($user);

		$this->cloudManager->callRequest('cloud/confirm-user-registration', [
			'domain' => (new Url(Helpers::getCurrentUrl()))->getDomain(3),
			'locale' => 'cs',
			'email' => $email,
			'setPasswordUrl' => $password ? null : Helpers::getBaseUrl() . '/admin/set-user-password?userId=' . $user->getId(),
			'loginUrl' => Helpers::getBaseUrl() . '/admin',
		], 'POST');

		$this->sendOk([
			'id' => $user->getId(),
		]);
	}


	public function deleteDefault(string $id): void
	{
		try {
			$user = $this->userManager->getUserById($id);
		} catch (NoResultException | NonUniqueResultException $e) {
			$this->sendError('User "' . $id . '" does not exist.');

			return;
		}

		$user->setActive(false);
		$this->entityManager->flush($user);
		$this->flashMessage('User was marked as deleted.', 'success');
		$this->sendOk();
	}


	public function actionRevertUser(string $id): void
	{
		try {
			$user = $this->userManager->getUserById($id);
		} catch (NoResultException | NonUniqueResultException $e) {
			$this->sendError('User "' . $id . '" does not exist.');

			return;
		}

		$user->setActive(true);
		$this->entityManager->flush($user);
		$this->flashMessage('User was reverted to active state.', 'success');
		$this->sendOk();
	}


	/**
	 * Check if user already exist.
	 */
	public function actionValidateUser(string $email): void
	{
		$this->sendJson([
			'exist' => $this->userExist($email),
		]);
	}


	public function actionOverview(string $id): void
	{
		try {
			$user = $this->userManager->getUserById($id);
		} catch (NoResultException | NonUniqueResultException $e) {
			$this->sendError('User "' . $id . '" does not exist.');

			return;
		}

		$this->sendJson([
			'form' => [
				'username' => $user->getUsername(),
				'fullName' => $user->getName(),
				'email' => $user->getEmail(),
				'phone' => (static function (?string $phone): array {
					if ($phone === null) {
						return [
							'original' => '',
							'region' => 420,
							'phone' => '',
						];
					}
					if (preg_match('/^\+(?<region>\d+)\s*(?<phone>.+)$/', $phone, $parser)) {
						return [
							'original' => $phone,
							'region' => (int) $parser['region'],
							'phone' => $parser['phone'],
						];
					}

					return [
						'original' => $phone,
						'region' => 420,
						'phone' => $phone,
					];
				})($user->getPhone()),
				'avatarUrl' => $user->getAvatarUrl(),
			],
			'iconUrl' => $user->getAvatarUrl() ?? 'https://www.gravatar.com/avatar/' . md5($user->getEmail()) . '?s=256',
			'created' => $user->getCreateDate(),
			'meta' => (static function (array $data): array {
				$return = [];
				foreach ($data as $key => $value) {
					if (\is_scalar($value) === true) {
						$return[$key] = $value;
					}
				}

				return $return;
			})($user->getMetaData()),
		]);
	}


	public function postOverview(string $id, string $username, string $email, string $name): void
	{
		try {
			$user = $this->userManager->getUserById($id);
		} catch (NoResultException | NonUniqueResultException $e) {
			$this->sendError('User "' . $id . '" does not exist.');

			return;
		}

		$this->setRealUserName($user, $name);
		$user->setUsername($username);

		try {
			$user->addEmail($email);
		} catch (\InvalidArgumentException $e) {
			$this->sendError($e->getMessage());
		}

		$this->entityManager->flush();
		$this->flashMessage('User settings was saved.', 'success');
		$this->sendOk();
	}


	public function actionRandomPassword(): void
	{
		$this->sendJson([
			'numbers' => Random::generate(12, '0-9'),
			'simple' => Random::generate(8),
			'normal' => Random::generate(12, '0-9a-zA-Z'),
			'advance' => trim(Random::generate(16, '0-9a-zA-Z.-'), '.-'),
		]);
	}


	public function postSetPassword(string $id, string $password): void
	{
		try {
			$user = $this->userManager->getUserById($id);
		} catch (NoResultException | NonUniqueResultException $e) {
			$this->sendError('User "' . $id . '" does not exist.');

			return;
		}

		// TODO: if ($this->userManager->isSafePassword($password) === false) {
		// TODO:  $this->sendError('Password must be safe!');
		// TODO: }

		$user->setPassword($password);
		$this->entityManager->flush($user);

		$this->sendOk();
	}


	/**
	 * Return info about user's password
	 */
	public function actionSecurity(string $id): void
	{
		try {
			$user = $this->userManager->getUserById($id);
		} catch (NoResultException | NonUniqueResultException $e) {
			$this->sendError('User "' . $id . '" does not exist.');

			return;
		}

		try {
			/** @var UserMeta $meta */
			$meta = $this->entityManager->getRepository(UserMeta::class)
				->createQueryBuilder('meta')
				->where('meta.user = :userId')
				->andWhere('meta.key = :key')
				->setParameters([
					'userId' => $id,
					'key' => 'password-last-changed-date',
				])
				->setMaxResults(1)
				->getQuery()
				->getSingleResult();

		} catch (NoResultException | NonUniqueResultException $e) {
			$meta = new UserMeta($user, 'password-last-changed-date', $user->getRegisterDate()->format('Y-m-d'));
			$this->entityManager->persist($meta)->flush($meta);
		}

		$this->sendJson([
			'lastChangedPassword' => DateTime::from($meta->getValue())->format('F j, Y'),
			'twoFactorAuth' => $user->getOtpCode() !== null,
		]);
	}


	public function postCancelOauth(string $id): void
	{
		try {
			$user = $this->userManager->getUserById($id);
		} catch (NoResultException | NonUniqueResultException $e) {
			$this->sendError('User "' . $id . '" does not exist.');

			return;
		}

		$user->setOtpCode(null);
		$this->entityManager->flush($user);

		$this->sendOk();
	}


	public function actionGenerateOauth(string $id): void
	{
		try {
			$user = $this->userManager->getUserById($id);
		} catch (NoResultException | NonUniqueResultException $e) {
			$this->sendError('User "' . $id . '" does not exist.');

			return;
		}

		if ($user->getOtpCode() !== null) {
			$this->sendError('OTP code already exist.');
		}

		$otpCode = $this->userManager->generateOtpCode();
		$otpCodeHash = md5($opCodeHuman = Helpers::otpBase32Encode($otpCode));

		$this->getCache('user-endpoint-otp')->save($otpCodeHash, $otpCode, [
			Cache::EXPIRE => '10 minutes',
		]);

		$this->sendJson([
			'account' => $user->getUsername(),
			'otpCode' => [
				'hash' => $otpCodeHash,
				'human' => $opCodeHuman,
			],
			'qrCodeUrl' => Helpers::getOtpQrUrl(
				(new Url(Helpers::getCurrentUrl()))->getDomain(3) . ' | Baraja',
				$user->getUsername(),
				$otpCode
			),
		]);
	}


	public function postSetAuth(string $id, string $hash, string $code): void
	{
		/** @var string|null $otpCode */
		$otpCode = $this->getCache('user-endpoint-otp')->load($hash);

		if ($otpCode === null) {
			$this->sendError('Hash is invalid or already expired.');
		}

		if (Helpers::checkAuthenticatorOtpCodeManually($otpCode, (int) $code) === true) {
			try {
				$user = $this->userManager->getUserById($id);
			} catch (NoResultException | NonUniqueResultException $e) {
				$this->sendError('User "' . $id . '" does not exist.');

				return;
			}

			$user->setOtpCode($otpCode);
			$this->entityManager->flush($user);
			$this->sendOk();
		}

		$this->sendError('OTP code does not work.');
	}


	public function postMarkUserAsAdmin(string $id, string $password): void
	{
		try {
			$user = $this->userManager->getUserById($id);
		} catch (NoResultException | NonUniqueResultException $e) {
			$this->sendError('User "' . $id . '" does not exist.');

			return;
		}

		/** @var User $adminUser */
		$adminUser = $this->getUserEntity();

		if ($adminUser->passwordVerify($password) === false) {
			$this->sendError('Admin password is incorrect.');
		}

		$user->addRole('admin');
		$this->entityManager->flush($user);
		$this->sendOk();
	}


	/**
	 * Return info about user's login history.
	 */
	public function actionLoginHistory(string $id, int $page = 1, int $limit = 32): void
	{
		$logins = $this->entityManager->getRepository(UserLogin::class)
			->createQueryBuilder('userLogin')
			->select('PARTIAL userLogin.{id, ip, hostname, userAgent, loginDatetime}')
			->where('userLogin.user = :userId')
			->setParameter('userId', $id)
			->setMaxResults($limit)
			->setFirstResult(($page - 1) * $limit)
			->orderBy('userLogin.loginDatetime', 'DESC')
			->getQuery()
			->getArrayResult();

		if ($page === 1 && $logins === []) {
			$count = 0;
		} else {
			try {
				$count = (int) $this->entityManager->getRepository(UserLogin::class)
					->createQueryBuilder('userLogin')
					->select('COUNT(userLogin.id)')
					->getQuery()
					->getSingleScalarResult();
			} catch (NoResultException | NonUniqueResultException $e) {
				$count = 0;
			}
		}

		$userAgentIterator = 1;
		$userAgents = [];
		$userAgentIdToHaystack = [];
		$return = [];
		foreach ($logins as $login) {
			if (isset($userAgents[$hash = md5($login['userAgent'])]) === false) {
				$userAgents[$hash] = $userAgentIterator++;
				$userAgentIdToHaystack[$userAgents[$hash]] = $login['userAgent'];
			}

			$return[] = [
				'ip' => $login['ip'],
				'hostname' => $login['hostname'],
				'userAgent' => $userAgents[$hash],
				'loginDatetime' => $login['loginDatetime'],
			];
		}

		$this->sendJson([
			'items' => $return,
			'userAgents' => $userAgentIdToHaystack,
			'count' => [
				'items' => \count($return),
				'allItems' => $count,
				'userAgents' => \count($userAgents),
			],
			'paginator' => (new Paginator)
				->setItemCount($count)
				->setItemsPerPage($limit)
				->setPage($page),
		]);
	}


	public function actionLoginAs(string $id): void
	{
		try {
			$user = $this->userManager->getUserById($id);
		} catch (NoResultException | NonUniqueResultException $e) {
			$this->sendError('User "' . $id . '" does not exist.');

			return;
		}

		try {
			$this->userManager->getUserStorage()->setIdentity($user)->setAuthenticated(true);
		} catch (\Throwable $e) {
			$this->sendError($e->getMessage());
		}

		$this->sendOk([
			'redirectUrl' => Helpers::getBaseUrl(),
		]);
	}


	public function actionSaveMeta(string $id, string $key, ?string $value = null): void
	{
		$this->userManager->setMeta($id, $key, $value);
		$this->sendOk();
	}


	public function actionSavePhoto(string $id, string $url): void
	{
		if (Validators::isUrl($url) === false) {
			$this->sendError('URL must be absolute valid URL.');
		}
		if (!($imageHeaders = @get_headers($url)) || ($imageHeaders[0] ?? '') === 'HTTP/1.1 404 Not Found') {
			$this->sendError('URL does not exist.');
		}
		foreach ($imageHeaders as $imageHeader) {
			if (preg_match('/^Content-Type:\s(.+)$/', $imageHeader, $headerParser)
				&& !\in_array($headerParser[1], ['image/jpeg', 'image/png', 'image/gif'], true)) {
				$this->sendError('Image type is not valid, because "' . $headerParser[1] . '" given.');
			}
		}
		try {
			$user = $this->userManager->getUserById($id);
		} catch (NoResultException | NonUniqueResultException $e) {
			$this->sendError('User "' . $id . '" does not exist.');

			return;
		}

		$user->setAvatarUrl($url);
		$this->entityManager->flush($user);
		$this->flashMessage('User photo has been changed.', 'success');

		$this->sendOk();
	}


	public function actionSavePhone(string $id, string $phone, int $region = 420): void
	{
		try {
			$user = $this->userManager->getUserById($id);
		} catch (NoResultException | NonUniqueResultException $e) {
			$this->sendError('User "' . $id . '" does not exist.');

			return;
		}
		try {
			$user->setPhone($phone, $region);
		} catch (\Throwable $e) {
			$this->sendError($e->getMessage());
		}

		$this->entityManager->flush($user);
		$this->flashMessage('User phone has been changed.', 'success');

		$this->sendOk();
	}


	public function postSetUserPassword(string $id, string $password): void
	{
		try {
			$currentUser = $this->userManager->getUserById((string) $this->getUser()->getId());
		} catch (NoResultException | NonUniqueResultException $e) {
			$this->sendError('User is not logged in.');

			return;
		}
		try {
			$user = $this->userManager->getUserById($id);
		} catch (NoResultException | NonUniqueResultException $e) {
			$this->sendError('User "' . $id . '" does not exist.');

			return;
		}
		if ($currentUser->getId() !== $user->getId() && \in_array('admin', $currentUser->getRoles(), true) === false) {
			$this->sendError('Current user must be admin for change password.');
		}

		$user->setPassword($password);
		$this->entityManager->flush($user);
		$this->userManager->setMeta($user->getId(), 'password-last-changed-date', date('Y-m-d'));
		$this->flashMessage('User password has been changed.', 'success');

		$this->sendOk();
	}


	private function userExist(string $email): bool
	{
		try {
			$this->entityManager->getRepository(User::class)
				->createQueryBuilder('user')
				->select('PARTIAL user.{id}')
				->where('user.username = :username')
				->setParameter('username', $email)
				->setMaxResults(1)
				->getQuery()
				->getSingleResult();

			return true;
		} catch (NoResultException | NonUniqueResultException $e) {
		}

		return false;
	}


	/**
	 * @return string[]
	 */
	private function getAllRoles(): array
	{
		$roles = [];
		$countTypes = [];
		foreach ($this->getAllUsers() as $user) {
			foreach ($user['roles'] ?? [] as $role) {
				$roles[$role] = true;
				if (isset($countTypes[$role]) === false) {
					$countTypes[$role] = 1;
				} else {
					$countTypes[$role]++;
				}
			}
		}

		$roles = array_keys($roles);
		sort($roles);

		$return = [];
		foreach ($roles as $role) {
			$return[$role] = Strings::firstUpper($role) . ' (' . $countTypes[$role] . ')';
		}

		return $return;
	}


	private function setRealUserName(User $user, string $name): void
	{
		$nameParser = Helpers::nameParser($name);
		$user->setFirstName($nameParser['firstName']);
		$user->setLastName($nameParser['lastName']);
		$this->userManager->setMeta($user->getId(), 'name--degree-before', $nameParser['degreeBefore']);
		$this->userManager->setMeta($user->getId(), 'name--degree-after', $nameParser['degreeAfter']);
	}


	/**
	 * @return mixed[][]
	 */
	private function getAllUsers(): array
	{
		static $cache;

		return $cache ?? $cache = $this->entityManager->getRepository(User::class)
				->createQueryBuilder('user')
				->select('PARTIAL user.{id, roles, active}')
				->getQuery()
				->getArrayResult();
	}
}
