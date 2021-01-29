<?php

declare(strict_types=1);

namespace Baraja\Cms\Api;


use Baraja\BarajaCloud\CloudManager;
use Baraja\Cms\Helpers;
use Baraja\Cms\User\Entity\CmsUser;
use Baraja\Cms\User\Entity\User;
use Baraja\Cms\User\Entity\UserLogin;
use Baraja\Cms\User\Entity\UserMeta;
use Baraja\Cms\User\UserManager;
use Baraja\Doctrine\EntityManager;
use Baraja\Plugin\PluginManager;
use Baraja\StructuredApi\BaseEndpoint;
use Baraja\Url\Url;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Nette\Caching\Cache;
use Nette\Utils\DateTime;
use Nette\Utils\Paginator;
use Nette\Utils\Random;
use Nette\Utils\Strings;
use Nette\Utils\Validators;
use Tracy\Debugger;
use Tracy\ILogger;

final class UserEndpoint extends BaseEndpoint
{
	private UserManager $userManager;

	private EntityManager $entityManager;

	private CloudManager $cloudManager;

	private PluginManager $pluginManager;


	public function __construct(
		UserManager $userManager,
		EntityManager $entityManager,
		CloudManager $cloudManager,
		PluginManager $pluginManager
	) {
		$this->userManager = $userManager;
		$this->entityManager = $entityManager;
		$this->cloudManager = $cloudManager;
		$this->pluginManager = $pluginManager;
	}


	/**
	 * Returns all users
	 *
	 * @param string|null $active String 'active' => show only active users, 'deleted' => show only deleted users, null => all users
	 */
	public function actionDefault(
		int $page = 1,
		int $limit = 32,
		?string $role = null,
		?string $query = null,
		?string $active = null
	): void {
		$currentUserId = $this->getUser()->getId();
		/** @var CmsUser $currentUser */
		$currentUser = $this->entityManager->getRepository($this->userManager->getDefaultEntity())->find($currentUserId);
		$selection = $this->entityManager->getRepository($this->userManager->getDefaultEntity())->createQueryBuilder('user');
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
			$orx = $selection->expr()->orX();
			$isMysql = ($this->entityManager->getConnection()->getParams()['driver'] ?? '') === 'pdo_mysql';
			if (preg_match('/^(\S+)\s+(.+)$/', $query = trim((string) preg_replace('/\s+/', ' ', $query)), $queryParser)) {
				$orx->add('user.username LIKE :firstName');
				$orx->add('user.username LIKE :lastName');
				$orx->add('user.firstName LIKE :firstName');
				$orx->add('user.lastName LIKE :lastName');
				if ($isMysql) {
					$orx->add('user.emails LIKE :firstName');
					$orx->add('user.emails LIKE :lastName');
				}
				$selection->andWhere($orx)
					->setParameter('firstName', $queryParser[1] . '%')
					->setParameter('lastName', $queryParser[2] . '%');
			} else {
				$orx->add('user.username LIKE :query');
				$orx->add('user.firstName LIKE :query');
				$orx->add('user.lastName LIKE :query');
				$orx->add('user.phone LIKE :query');
				if ($isMysql) {
					$orx->add('user.emails LIKE :query');
				}
				$selection->andWhere($orx)
					->setParameter('query', '%' . $query . '%');
			}
		}

		$users = $selection->select('PARTIAL user.{id, firstName, lastName, password, emails, phone, roles, active, avatarUrl, otpCode}')
			->setMaxResults($limit)
			->setFirstResult(($page - 1) * $limit)
			->orderBy('user.createDate', 'DESC')
			->getQuery()
			->getArrayResult();

		$return = [];
		foreach ($users as $user) {
			$return[] = [
				'id' => $user['id'],
				'name' => (static function () use ($user): ?string {
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
				'2fa' => $user['otpCode'] !== null,
				'verifying' => $user['password'] === '---empty-password---',
			];
		}

		$this->sendJson([
			'list' => $return,
			'roles' => $this->formatBootstrapSelectArray($allRoles),
			'isCurrentUserUsing2fa' => $currentUser->getOtpCode() !== null,
			'currentUserId' => $currentUserId,
			'statusCount' => [
				'all' => \count($allUsers = $this->getAllUsers()),
				'active' => \count(array_filter($allUsers, static fn (array $item): bool => $item['active'] === true)),
				'deleted' => \count(array_filter($allUsers, static fn (array $item): bool => $item['active'] === false)),
			],
			'paginator' => (new Paginator)
				->setItemCount(\count($allUsers))
				->setItemsPerPage($limit)
				->setPage($page),
		]);
	}


	public function createDefault(
		string $fullName,
		string $email,
		string $role,
		?string $phone = null,
		?string $password = null
	): void {
		if ($this->userExist($email) === true) {
			$this->sendError('User "' . $email . '" already exist.');
		}
		try {
			try {
				/** @phpstan-ignore-next-line */
				$ref = new \ReflectionClass($this->userManager->getDefaultEntity());
				/** @var CmsUser $user */
				$user = $ref->newInstanceArgs([$email, $password ?: '', $email, CmsUser::ROLE_USER]);
			} catch (\Throwable $e) {
				if (class_exists(Debugger::class)) {
					Debugger::log($e, ILogger::CRITICAL);
				}
				$this->sendError('Can not create user because user storage is broken.');

				return;
			}
			$user->setPhone($phone);
			$user->addRole($role);
			$this->entityManager->persist($user)->flush();
		} catch (\InvalidArgumentException $e) {
			$this->sendError($e->getMessage());

			return;
		}

		$this->setRealUserName($user, $fullName);
		$this->entityManager->flush();

		$this->cloudManager->callRequest('cloud/confirm-user-registration', [
			'domain' => Url::get()->getNetteUrl()->getDomain(3),
			'locale' => 'cs',
			'email' => $email,
			'setPasswordUrl' => $password ? null : Url::get()->getBaseUrl() . '/admin/set-user-password?userId=' . $user->getId(),
			'loginUrl' => Url::get()->getBaseUrl() . '/admin',
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
		$this->entityManager->flush();
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
		$this->entityManager->flush();
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
			'advance' => Random::generate(6, '0-9a-zA-Z') . '-' . Random::generate(6, '0-9a-zA-Z') . '-' . Random::generate(6, '0-9a-zA-Z'),
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
		$this->entityManager->flush();
		$this->sendOk();
	}


	/**
	 * Return info about user's password
	 */
	public function actionSecurity(string $id): void
	{
		try {
			/** @var User $user */
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
			$this->entityManager->persist($meta)->flush();
		}

		$this->sendJson([
			'lastChangedPassword' => DateTime::from($meta->getValue() ?? 'now')->format('F j, Y'),
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
		$this->entityManager->flush();
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
				Url::get()->getNetteUrl()->getDomain(3) . ' | Baraja',
				$user->getUsername(),
				$otpCode,
			),
		]);
	}


	public function postSetAuth(string $id, string $hash, string $code): void
	{
		/** @var string|null $otpCode */
		$otpCode = $this->getCache('user-endpoint-otp')->load($hash);

		if ($otpCode === null) {
			$this->sendError('Hash is invalid or already expired.');

			return;
		}
		if (Helpers::checkAuthenticatorOtpCodeManually($otpCode, (int) $code) === true) {
			try {
				$user = $this->userManager->getUserById($id);
			} catch (NoResultException | NonUniqueResultException $e) {
				$this->sendError('User "' . $id . '" does not exist.');

				return;
			}

			$user->setOtpCode($otpCode);
			$this->entityManager->flush();
			$this->sendOk();
		}

		$this->sendError('OTP code does not work.');
	}


	public function actionPermissions(string $id): void
	{
		$currentUser = $this->userManager->getUserById($this->getUser()->getId());
		if ($currentUser->isAdmin() === false) {
			$this->sendError('Permissions settings is available only for admin user.');
		}
		try {
			$user = $this->userManager->getUserById($id);
		} catch (NoResultException | NonUniqueResultException $e) {
			$this->sendError('User "' . $id . '" does not exist.');

			return;
		}

		$plugins = [];
		foreach ($this->pluginManager->getPluginInfoEntities() as $plugin) {
			$components = [];
			foreach ($this->pluginManager->getComponents($plugin, null) as $component) {
				$components[] = [
					'active' => $user->containPrivilege('component-' . $component->getName()),
					'tab' => $component->getTab(),
					'name' => $component->getName(),
				];
			}
			$plugins[] = [
				'active' => $user->containPrivilege('plugin-' . $plugin->getSanitizedName()),
				'name' => $plugin->getSanitizedName(),
				'type' => $plugin->getType(),
				'realName' => $plugin->getRealName(),
				'components' => $components,
			];
		}

		$this->sendJson([
			'isAdmin' => $user->isAdmin(),
			'roles' => $user->getRoles(),
			'items' => $plugins,
		]);
	}


	/**
	 * @param string[] $roles
	 * @param mixed[] $permissions
	 */
	public function postSavePermissions(string $id, array $roles, array $permissions): void
	{
		$currentUser = $this->userManager->getUserById($this->getUser()->getId());
		if ($currentUser->isAdmin() === false) {
			$this->sendError('Permissions settings is available only for admin user.');
		}
		try {
			$user = $this->userManager->getUserById($id);
		} catch (NoResultException | NonUniqueResultException $e) {
			$this->sendError('User "' . $id . '" does not exist.');

			return;
		}
		$roles = array_map(fn (string $role): string => strtolower($role), $roles);
		if ($user->isAdmin() === false && \in_array('admin', $roles, true) === true) {
			$this->sendError('You cannot set the administrator role manually. To maintain security, use the "Set as admin" button.');
		}

		$privileges = [];
		foreach ($permissions as $plugin) {
			if (($plugin['active'] ?? false) === true) {
				$pluginName = (string) ($plugin['name'] ?? '');
				$pluginEntity = $this->pluginManager->getPluginByName(Helpers::formatPresenterNameByUri($pluginName));
				$pluginComponents = $this->pluginManager->getComponents($pluginEntity, null);
				$privileges[] = 'plugin-' . $pluginName;
				foreach ($plugin['components'] ?? [] as $component) {
					if (($component['active'] ?? false) === true) {
						foreach ($pluginComponents as $componentEntity) {
							if ($componentEntity->getName() === ($component['name'] ?? '')) {
								$privileges[] = 'component-' . $componentEntity->getName();
								break;
							}
						}
					}
				}
			}
		}

		$user->resetPrivileges();
		foreach ($privileges as $privilege) {
			$user->addPrivilege((string) $privilege);
		}

		$user->resetRoles();
		foreach ($roles as $role) {
			$user->addRole($role);
		}

		$this->entityManager->flush();
		$this->flashMessage('User permissions has been set.', 'success');
		$this->sendOk();
	}


	public function postMarkUserAsAdmin(string $id, string $password): void
	{
		$currentUser = $this->userManager->getUserById($this->getUser()->getId());
		if ($currentUser->isAdmin() === false) {
			$this->sendError('Permissions settings is available only for admin user.');
		}
		try {
			$user = $this->userManager->getUserById($id);
		} catch (NoResultException | NonUniqueResultException $e) {
			$this->sendError('User "' . $id . '" does not exist.');

			return;
		}
		if ($currentUser->passwordVerify($password) === false) {
			$this->sendError('Admin password is incorrect.');
		}

		$user->addRole('admin');
		$this->entityManager->flush();
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
			$this->userManager->createIdentity($user);
		} catch (\Throwable $e) {
			$this->sendError($e->getMessage());
		}

		$this->sendOk([
			'redirectUrl' => Url::get()->getBaseUrl(),
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
		foreach ((array) $imageHeaders as $imageHeader) {
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
		$this->entityManager->flush();
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

		$this->entityManager->flush();
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
		$this->userManager->setMeta((string) $user->getId(), 'password-last-changed-date', date('Y-m-d'));
		$this->entityManager->flush();
		$this->flashMessage('User password has been changed.', 'success');

		$this->sendOk();
	}


	private function userExist(string $email): bool
	{
		try {
			$this->entityManager->getRepository($this->userManager->getDefaultEntity())
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
				$roles[$role = (string) $role] = true;
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
			$role = (string) $role;
			$return[$role] = Strings::firstUpper($role) . ' (' . $countTypes[$role] . ')';
		}

		return $return;
	}


	private function setRealUserName(CmsUser $user, string $name): void
	{
		$nameParser = Helpers::nameParser($name);
		$user->setFirstName($nameParser['firstName']);
		$user->setLastName($nameParser['lastName']);
		$this->userManager->setMeta((string) $user->getId(), 'name--degree-before', $nameParser['degreeBefore']);
		$this->userManager->setMeta((string) $user->getId(), 'name--degree-after', $nameParser['degreeAfter']);
		$this->entityManager->flush();
	}


	/**
	 * @return mixed[][]
	 */
	private function getAllUsers(): array
	{
		static $cache;

		return $cache ?? $cache = $this->entityManager->getRepository($this->userManager->getDefaultEntity())
				->createQueryBuilder('user')
				->select('PARTIAL user.{id, roles, active}')
				->getQuery()
				->getArrayResult();
	}
}
